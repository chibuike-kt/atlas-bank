<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;
use App\Domain\Transfers\TransferEventWriter;


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
          t.status AS transfer_status,
          t.sender_user_id,
          t.sender_account_id,
          t.recipient_user_id,
          t.recipient_account_id,
          t.amount_minor,
          t.currency
        FROM transfers t
        WHERE t.id = ?
        LIMIT 1
        FOR UPDATE

      ");
      $stmt->execute([$transferId]);
      $t = $stmt->fetch();

      if (!$t) throw new \DomainException('transfer_not_found');
      if ((string)$t['sender_user_id'] !== $requestingUserId) throw new \DomainException('not_allowed');

      if ((string)$t['transfer_status'] === 'reversed') throw new \DomainException('already_reversed');
      if ((string)$t['transfer_status'] !== 'posted') throw new \DomainException('not_reversible');

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

      // Mark transfer reversed + link reversal journal
      $stmt = $pdo->prepare("
  UPDATE transfers
  SET status = 'reversed',
      reversal_journal_id = ?,
      reversed_at = CURRENT_TIMESTAMP
  WHERE id = ?
");
      $stmt->execute([$reversalJournalId, $transferId]);


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

    $reference = 'internal_' . bin2hex(random_bytes(12));
    $transferId = Uuid::uuid4()->toString();

    // We create the transfer row early as pending so we can mark failed if needed.
    $pdo->beginTransaction();
    try {
      // Resolve recipient user (no locks needed yet)
      $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
      $stmt->execute([$recipientEmail]);
      $recipient = $stmt->fetch();
      if (!$recipient) throw new \DomainException('recipient_not_found');
      $recipientUserId = (string)$recipient['id'];

      if ($recipientUserId === $senderUserId) throw new \DomainException('cannot_transfer_to_self');

      // Lock accounts (consistent ordering to avoid deadlocks)
      $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? AND currency = ? LIMIT 1");
      $stmt->execute([$senderUserId, $currency]);
      $s = $stmt->fetch();
      if (!$s) throw new \DomainException('sender_account_not_found');
      $senderAccountId = (string)$s['id'];

      $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? AND currency = ? LIMIT 1");
      $stmt->execute([$recipientUserId, $currency]);
      $r = $stmt->fetch();
      if (!$r) throw new \DomainException('recipient_account_not_found');
      $recipientAccountId = (string)$r['id'];

      // Create journal in pending state initially (optional but clean)
      $journalId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO journals (id, type, reference, status) VALUES (?, 'internal_transfer', ?, 'pending')");
      $stmt->execute([$journalId, $reference]);

      // Create transfer as pending
      $stmt = $pdo->prepare("
      INSERT INTO transfers
        (id, journal_id, sender_user_id, sender_account_id, recipient_user_id, recipient_account_id, amount_minor, currency, memo, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
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

      TransferEventWriter::record($pdo, $transferId, $senderUserId, null, 'pending', null, [
        'reference' => $reference,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'recipient_user_id' => $recipientUserId
      ]);

      // Now lock both accounts FOR UPDATE in deterministic order
      $a1 = $senderAccountId < $recipientAccountId ? $senderAccountId : $recipientAccountId;
      $a2 = $senderAccountId < $recipientAccountId ? $recipientAccountId : $senderAccountId;

      $stmt = $pdo->prepare("SELECT id, balance_minor FROM accounts WHERE id IN (?, ?) FOR UPDATE");
      $stmt->execute([$a1, $a2]);
      $rows = $stmt->fetchAll();
      if (count($rows) !== 2) throw new \DomainException('account_not_found');

      // Re-fetch sender balance under lock
      $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id = ? LIMIT 1");
      $stmt->execute([$senderAccountId]);
      $sb = $stmt->fetch();
      if (!$sb) throw new \DomainException('sender_account_not_found');
      $senderBalance = (int)$sb['balance_minor'];

      if ($senderBalance < $amountMinor) throw new \DomainException('insufficient_funds');

      // Post double-entry postings
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'debit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $senderAccountId, $amountMinor, $currency]);

      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'credit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $recipientAccountId, $amountMinor, $currency]);

      // Update balances
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ?");
      $stmt->execute([$amountMinor, $senderAccountId]);

      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor = balance_minor + ? WHERE id = ?");
      $stmt->execute([$amountMinor, $recipientAccountId]);

      // Mark journal posted
      $stmt = $pdo->prepare("UPDATE journals SET status = 'posted' WHERE id = ?");
      $stmt->execute([$journalId]);

      // Mark transfer posted
      $stmt = $pdo->prepare("UPDATE transfers SET status = 'posted', failure_reason = NULL WHERE id = ?");
      $stmt->execute([$transferId]);

      TransferEventWriter::record($pdo, $transferId, $senderUserId, 'pending', 'posted', null, [
        'journal_id' => $journalId
      ]);

      // Audit
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
        'status' => 'posted'
      ];
    } catch (\DomainException $e) {
      if ($pdo->inTransaction()) {
        // Best-effort mark failed if transfer exists
        try {
          $stmt = $pdo->prepare("UPDATE transfers SET status = 'failed', failure_reason = ? WHERE id = ?");
          $stmt->execute([$e->getMessage(), $transferId]);

          TransferEventWriter::record($pdo, $transferId, $senderUserId, 'pending', 'failed', $e->getMessage(), [
            'reference' => $reference
          ]);

          // Mark journal failed if present
          $stmt = $pdo->prepare("UPDATE journals SET status = 'reversed' WHERE reference = ? AND type='internal_transfer'");
          $stmt->execute([$reference]);
        } catch (\Throwable $ignore) {
        }
        $pdo->rollBack();
      }
      throw $e;
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) {
        try {
          $stmt = $pdo->prepare("UPDATE transfers SET status = 'failed', failure_reason = 'internal_error' WHERE id = ?");
          $stmt->execute([$transferId]);

          TransferEventWriter::record($pdo, $transferId, $senderUserId, 'pending', 'failed', 'internal_error', [
            'reference' => $reference
          ]);
        } catch (\Throwable $ignore) {
        }
        $pdo->rollBack();
      }
      throw $e;
    }
  }
}
