<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use PDO;
use Ramsey\Uuid\Uuid;

final class TransferEventWriter
{
  public static function record(
    PDO $pdo,
    string $transferId,
    ?string $actorUserId,
    ?string $fromStatus,
    string $toStatus,
    ?string $reason,
    array $meta
  ): void {
    $stmt = $pdo->prepare("
      INSERT INTO transfer_events
        (id, transfer_id, actor_user_id, from_status, to_status, reason, meta_json)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      Uuid::uuid4()->toString(),
      $transferId,
      $actorUserId,
      $fromStatus,
      $toStatus,
      $reason,
      json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
  }
}
