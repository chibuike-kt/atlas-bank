<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;

final class InternalTransferService
{
  public function reverse(string $requestingUserId, string $transferId): array
  {
    $pdo = Connection::pdo($this->config['db']);
    $reference = 'reversal_' . bin2hex(random_bytes(12));

    $pdo->beginTransaction();
    try {
      // Lock transfer row via join on journal for consistent view
      $stmt = $pdo->prepare("
        SELECT
          t.id AS transfer_id,
          t.journal_id AS original_journal_id,
          t.sender_user_id,
          t.sender_account_id,
          t.recipient_user_id,
          t.recipient_account_id,
          t.amount_minor,
          t.currency,
          j.status AS journal_status
        FROM transfers t
        JOIN journals j ON j.id = t.journal_id
        WHERE t.id = ?
        LIMIT 1
        FOR UPDATE
      ");
      $stmt->execute([$transferId]);
      $t = $stmt->fetch();

      if (!$t) throw new \DomainException('transfer_not_found');
      if ((string)$t['sender_user_id'] !== $requestingUserId) throw new \DomainException('not_allowed');

      if ((string)$t['journal_status'] === 'reversed') throw new \DomainException('already_reversed');
      if ((string)$t['journal_status'] !== 'posted') throw new \DomainException('not_reversible');

      $amount = (int)$t['amount_minor'];
      $currency = (string)$t['currency'];

      $senderAccountId = (string)$t['sender_account_id'];
      $recipientAccountId = (string)$t['recipient_account_id'];
      $recipientUserId = (string)$t['recipient_user_id'];
      $originalJournalId = (string)$t['original_journal_id'];

      // Lock both accounts (order locks by account_id to reduce deadlock risk)
      $a1 = $senderAccountId < $recipientAccountId ? $senderAccountId : $recipientAccountId;
      $a2 = $senderAccountId < $recipientAccountId ? $recipientAccountId : $senderAccountId;

      $stmt = $pdo->prepare("SELECT id, balance_minor FROM accounts WHERE id IN (?, ?) FOR UPDATE");
      $stmt->execute([$a1, $a2]);
      $rows = $stmt->fetchAll();
      if (count($rows) !== 2) throw new \DomainException('account_not_found');

      // Ensure recipient still has enough to reverse (important!)
      $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id = ? LIMIT 1");
      $stmt->execute([$recipientAccountId]);
      $rb = $stmt->fetch();
      if (!$rb) throw new \DomainException('recipient_account_not_found');

      $recipientBalance = (int)$rb['balance_minor'];
      if ($recipientBalance < $amount) throw new \DomainException('recipient_insufficient_funds_for_reversal');

      // Create reversal journal
      $reversalJournalId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO journals (id, type, reference, status) VALUES (?, 'internal_transfer_reversal', ?, 'posted')");
      $stmt->execute([$reversalJournalId, $reference]);

      // Postings are the mirror:
      // recipient gets debited (money leaves recipient)
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'debit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $reversalJournalId, $recipientAccountId, $amount, $currency]);

      // sender gets credited (money returns to sender)
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'credit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $reversalJournalId, $senderAccountId, $amount, $currency]);

      // Update balances
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ?");
      $stmt->execute([$amount, $recipientAccountId]);

      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor = balance_minor + ? WHERE id = ?");
      $stmt->execute([$amount, $senderAccountId]);

      // Mark original journal reversed (immutability: we don't delete postings)
      $stmt = $pdo->prepare("UPDATE journals SET status = 'reversed' WHERE id = ?");
      $stmt->execute([$originalJournalId]);

      // Audit
      $stmt = $pdo->prepare("INSERT INTO audit_logs (id, actor_user_id, action, meta_json) VALUES (?, ?, 'internal_transfer_reversed', JSON_OBJECT(
        'transfer_id', ?, 'original_journal_id', ?, 'reversal_journal_id', ?, 'reference', ?, 'amount_minor', ?, 'currency', ?
      ))");
      $stmt->execute([
        Uuid::uuid4()->toString(),
        $requestingUserId,
        $transferId,
        $originalJournalId,
        $reversalJournalId,
        $reference,
        $amount,
        $currency
      ]);

      $pdo->commit();

      return [
        'transfer_id' => $transferId,
        'original_journal_id' => $originalJournalId,
        'reversal_journal_id' => $reversalJournalId,
        'reference' => $reference,
        'amount_minor' => $amount,
        'currency' => $currency,
        'recipient_user_id' => $recipientUserId
      ];
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  public function __construct(private array $config) {}

  public function transfer(
    string $senderUserId,
    string $recipientEmail,
    int $amountMinor,
    string $currency,
    string $memo
  ): array {
    $pdo = Connection::pdo($this->config['db']);

    // Reference should be stable; later we’ll use idempotency key ref.
    $reference = 'internal_' . bin2hex(random_bytes(12));

    $pdo->beginTransaction();
    try {
      // 1) Resolve recipient user
      $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
      $stmt->execute([$recipientEmail]);
      $recipient = $stmt->fetch();
      if (!$recipient) throw new \DomainException('recipient_not_found');
      $recipientUserId = (string)$recipient['id'];

      if ($recipientUserId === $senderUserId) throw new \DomainException('cannot_transfer_to_self');

      // 2) Lock sender account row (prevents double spend)
      $stmt = $pdo->prepare("SELECT id, balance_minor FROM accounts WHERE user_id = ? AND currency = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$senderUserId, $currency]);
      $senderAcct = $stmt->fetch();
      if (!$senderAcct) throw new \DomainException('sender_account_not_found');

      $senderAccountId = (string)$senderAcct['id'];
      $senderBalance = (int)$senderAcct['balance_minor'];

      if ($senderBalance < $amountMinor) throw new \DomainException('insufficient_funds');

      // 3) Lock recipient account row
      $stmt = $pdo->prepare("SELECT id, balance_minor FROM accounts WHERE user_id = ? AND currency = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$recipientUserId, $currency]);
      $recipientAcct = $stmt->fetch();
      if (!$recipientAcct) throw new \DomainException('recipient_account_not_found');

      $recipientAccountId = (string)$recipientAcct['id'];

      // 4) Create journal
      $journalId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO journals (id, type, reference, status) VALUES (?, 'internal_transfer', ?, 'posted')");
      $stmt->execute([$journalId, $reference]);

      // 5) Create postings (double-entry)
      // sender: debit (money leaves)
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'debit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $senderAccountId, $amountMinor, $currency]);

      // recipient: credit (money enters)
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'credit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $recipientAccountId, $amountMinor, $currency]);

      // 6) Update balances atomically (still inside row lock)
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ?");
      $stmt->execute([$amountMinor, $senderAccountId]);

      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor = balance_minor + ? WHERE id = ?");
      $stmt->execute([$amountMinor, $recipientAccountId]);

      // 7) Transfer record
      $transferId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("
        INSERT INTO transfers
          (id, journal_id, sender_user_id, sender_account_id, recipient_user_id, recipient_account_id, amount_minor, currency, memo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([
        $transferId,
        $journalId,
        $senderUserId,
        $senderAccountId,
        $recipientUserId,
        $recipientAccountId,
        $amountMinor,
        $currency,
        $memo !== '' ? $memo : null
      ]);

      // 8) Audit (append-only)
      $stmt = $pdo->prepare("INSERT INTO audit_logs (id, actor_user_id, action, meta_json) VALUES (?, ?, 'internal_transfer_posted', JSON_OBJECT(
        'transfer_id', ?, 'journal_id', ?, 'reference', ?, 'amount_minor', ?, 'currency', ?, 'recipient_user_id', ?
      ))");
      $stmt->execute([
        Uuid::uuid4()->toString(),
        $senderUserId,
        $transferId,
        $journalId,
        $reference,
        $amountMinor,
        $currency,
        $recipientUserId
      ]);

      $pdo->commit();

      return [
        'transfer_id' => $transferId,
        'journal_id' => $journalId,
        'reference' => $reference,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
      ];
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
