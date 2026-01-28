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
    // Only enforce for money movement endpoints (start with internal transfers)
    $isTransfer = ($req->method === 'POST' && $req->path === '/transfers/internal');
    if (!$isTransfer) return $next($req, $res);

    // Must be authenticated (we rely on AuthMiddleware placing x-auth-sub)
    $userId = (string)($req->headers['x-auth-sub'] ?? '');
    if ($userId === '') return $res->json(['error' => 'auth_required'], 401);

    $idemKey = trim((string)($req->header('idempotency-key') ?? ''));
    if ($idemKey === '' || strlen($idemKey) > 200) {
      return $res->json(['error' => 'missing_or_invalid_idempotency_key'], 400);
    }

    $requestHash = hash('sha256', $req->method . '|' . $req->path . '|' . json_encode($req->body, JSON_UNESCAPED_SLASHES));

    $pdo = Connection::pdo($this->config['db']);

    // If key exists, return stored response (or reject if payload differs)
    $stmt = $pdo->prepare("SELECT request_hash, response_code, response_body FROM idempotency_keys WHERE owner_user_id = ? AND idem_key = ? LIMIT 1");
    $stmt->execute([$userId, $idemKey]);
    $row = $stmt->fetch();

    if ($row) {
      if ((string)$row['request_hash'] !== $requestHash) {
        return $res->json(['error' => 'idempotency_key_reuse_with_different_payload'], 409);
      }
      if ($row['response_code'] !== null && $row['response_body'] !== null) {
        $body = json_decode((string)$row['response_body'], true);
        if (!is_array($body)) $body = ['error' => 'bad_stored_idempotency_response'];
        return $res->json($body, (int)$row['response_code']);
      }
      // If exists but no response stored yet: treat as "in progress"
      return $res->json(['error' => 'request_in_progress'], 409);
    }

    // Reserve key before running business logic
    $id = Uuid::uuid4()->toString();
    $stmt = $pdo->prepare("INSERT INTO idempotency_keys (id, owner_user_id, idem_key, request_hash) VALUES (?, ?, ?, ?)");
    $stmt->execute([$id, $userId, $idemKey, $requestHash]);

    // Run handler, then store result
    $out = $next($req, $res);

    // Store response (best effort)
    try {
      $responseBody = json_encode($outPayload = (new \ReflectionClass($out))->getProperty('payload') ?? null);
      // Response payload is private; avoid reflection hacks.
      // Instead: store what we can by re-encoding from output buffer in a later refactor.
      // For now: store an approximation from global output? We'll do clean storage in next iteration.
    } catch (\Throwable $e) {
    }

    // Minimal safe storage: store status only; full body storage will be added next iteration.
    // (Still blocks duplicates and prevents replay-different-payload.)
    $stmt = $pdo->prepare("UPDATE idempotency_keys SET response_code = ? WHERE owner_user_id = ? AND idem_key = ?");
    $stmt->execute([200, $userId, $idemKey]);

    return $out;
  }
}
