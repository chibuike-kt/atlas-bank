<?php

declare(strict_types=1);

namespace App\App\Security;

final class Password
{
  public static function hash(string $password): string
  {
    return password_hash($password, PASSWORD_ARGON2ID);
  }

  public static function verify(string $password, string $hash): bool
  {
    return password_verify($password, $hash);
  }
}
