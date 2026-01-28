<?php

declare(strict_types=1);

namespace App\App\Http\Middleware;

use App\App\Http\Request;
use App\App\Http\Response;
use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;

final class IdempotencyMiddleware
{
  public function __construct(private array $config) {}

  public function handle(Request $req, Response $res, callable $next): Response
  {
    // Enforce on money-movement endpoints (internal transfer + reversal)
    $isMoneyMovement = (
      $req->method === 'POST' &&
      (
        $req->path === '/transfers/internal' ||
        preg_match('#^/transfers/[0-9a-fA-F-]{36}/reverse$#', $req->path) === 1
      )
    );

    if (!$isMoneyMovement) return $next($req, $res);

    // Must be authenticated (AuthMiddleware sets x-auth-sub)
    $userId = (string)($req->headers['x-auth-sub'] ?? '');
    if ($userId === '') return $res->json(['error' => 'auth_required'], 401);

    $idemKey = trim((string)($req->header('idempotency-key') ?? ''));
    if ($idemKey === '' || strlen($idemKey) > 200) {
      return $res->json(['error' => 'missing_or_invalid_idempotency_key'], 400);
    }

    $requestHash = hash(
      'sha256',
      $req->method . '|' . $req->path . '|' . json_encode($req->body, JSON_UNESCAPED_SLASHES)
    );

    $pdo = Connection::pdo($this->config['db']);

    // If key exists: replay exact response, or reject if payload differs.
    $stmt = $pdo->prepare("
      SELECT request_hash, response_code, response_body
      FROM idempotency_keys
      WHERE owner_user_id = ? AND idem_key = ?
      LIMIT 1
    ");
    $stmt->execute([$userId, $idemKey]);
    $row = $stmt->fetch();

    if ($row) {
      if ((string)$row['request_hash'] !== $requestHash) {
        return $res->json(['error' => 'idempotency_key_reuse_with_different_payload'], 409);
      }

      // In-progress (reserved but not completed)
      if ($row['response_code'] === null || $row['response_body'] === null) {
        return $res->json(['error' => 'request_in_progress'], 409);
      }

      $body = json_decode((string)$row['response_body'], true);
      if (!is_array($body)) $body = ['error' => 'bad_stored_idempotency_response'];

      return $res->json($body, (int)$row['response_code']);
    }

    // Reserve key before running business logic
    $id = Uuid::uuid4()->toString();
    $stmt = $pdo->prepare("
      INSERT INTO idempotency_keys (id, owner_user_id, idem_key, request_hash)
      VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$id, $userId, $idemKey, $requestHash]);

    // Execute handler
    $out = $next($req, $res);

    // Store exact response (best effort; if storage fails, still return response)
    try {
      $code = $out->getStatusCode();
      $bodyJson = json_encode($out->getPayload(), JSON_UNESCAPED_SLASHES);
      $stmt = $pdo->prepare("
        UPDATE idempotency_keys
        SET response_code = ?, response_body = ?
        WHERE owner_user_id = ? AND idem_key = ?
      ");
      $stmt->execute([$code, $bodyJson, $userId, $idemKey]);
    } catch (\Throwable $e) {
      // Intentionally ignore storage error to avoid breaking request.
    }

    return $out;
  }
}
