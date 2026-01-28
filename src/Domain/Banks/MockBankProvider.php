<?php

declare(strict_types=1);

namespace App\Infrastructure\Banks;

use App\Domain\Banks\BankProvider;

final class MockBankProvider implements BankProvider
{
  public function initiateTransfer(array $payload): array
  {
    return [
      'status' => 'queued',
      'reference' => 'mock_' . bin2hex(random_bytes(8)),
      'payload' => $payload,
    ];
  }

  public function resolveAccount(string $bankCode, string $accountNumber): array
  {
    return [
      'ok' => true,
      'account_name' => 'MOCK USER',
      'bank_code' => $bankCode,
      'account_number' => $accountNumber,
    ];
  }
}
