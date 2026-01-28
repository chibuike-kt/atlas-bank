<?php

declare(strict_types=1);

namespace App\Domain\Banks;

interface BankProvider
{
  /**
   * Initiate NGN bank transfer.
   * Return a provider reference you can later poll/webhook.
   */
  public function initiateTransfer(array $payload): array;

  /** Resolve account name (NUBAN) / validation */
  public function resolveAccount(string $bankCode, string $accountNumber): array;
}
