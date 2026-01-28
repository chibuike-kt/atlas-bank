<?php

declare(strict_types=1);

namespace App\App\Http\Controllers;

use App\App\Http\Request;
use App\App\Http\Response;
use App\Domain\Transfers\InternalTransferService;

final class TransferController
{
  public function __construct(private array $config) {}

  public function internal(Request $req, Response $res): Response
  {
    $userId = (string)($req->headers['x-auth-sub'] ?? '');
    if ($userId === '') return $res->json(['error' => 'auth_required'], 401);

    $recipientEmail = strtolower(trim((string)($req->body['recipient_email'] ?? '')));
    $amountMinor = (int)($req->body['amount_minor'] ?? 0);
    $currency = strtoupper(trim((string)($req->body['currency'] ?? 'NGN')));
    $memo = trim((string)($req->body['memo'] ?? ''));

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
      return $res->json(['error' => 'invalid_recipient_email'], 422);
    }
    if ($amountMinor <= 0) {
      return $res->json(['error' => 'invalid_amount_minor'], 422);
    }
    if (!in_array($currency, ['NGN'], true)) {
      return $res->json(['error' => 'unsupported_currency'], 422);
    }
    if (strlen($memo) > 180) {
      return $res->json(['error' => 'memo_too_long'], 422);
    }

    $svc = new InternalTransferService($this->config);

    try {
      $result = $svc->transfer($userId, $recipientEmail, $amountMinor, $currency, $memo);
      return $res->json(['ok' => true] + $result, 201);
    } catch (\DomainException $e) {
      return $res->json(['error' => $e->getMessage()], 409);
    } catch (\Throwable $e) {
      return $res->json(['error' => 'internal_error'], 500);
    }
  }
}
