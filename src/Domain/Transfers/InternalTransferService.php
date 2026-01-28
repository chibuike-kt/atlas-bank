<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;

final class InternalTransferService
{
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
