<?php

declare(strict_types=1);

namespace App\App\Http\Controllers;

use App\App\Http\Request;
use App\App\Http\Response;
use App\App\Security\Password;
use App\App\Security\Jwt;
use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;

final class AuthController
{
  public function __construct(private array $config) {}

  public function register(Request $req, Response $res): Response
  {
    $email = strtolower(trim((string)($req->body['email'] ?? '')));
    $password = (string)($req->body['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
      return $res->json(['error' => 'invalid_input'], 422);
    }

    $pdo = Connection::pdo($this->config['db']);
    $userId = Uuid::uuid4()->toString();
    $hash = Password::hash($password);

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)");
      $stmt->execute([$userId, $email, $hash]);

      // Create primary NGN account
      $acctId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO accounts (id, user_id, currency, balance_minor) VALUES (?, ?, 'NGN', 0)");
      $stmt->execute([$acctId, $userId]);

      $pdo->commit();
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return $res->json(['error' => 'email_taken_or_db_error'], 409);
    }

    return $res->json(['ok' => true, 'user_id' => $userId], 201);
  }

  public function login(Request $req, Response $res): Response
  {
    $email = strtolower(trim((string)($req->body['email'] ?? '')));
    $password = (string)($req->body['password'] ?? '');

    $pdo = Connection::pdo($this->config['db']);
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !Password::verify($password, (string)$row['password_hash'])) {
      return $res->json(['error' => 'invalid_credentials'], 401);
    }

    $token = Jwt::issue($this->config, (string)$row['id']);

    return $res->json(['access_token' => $token, 'token_type' => 'Bearer'], 200);
  }
}
