<?php

declare(strict_types=1);

namespace App\App;

use Dotenv\Dotenv;

final class Bootstrap
{
  public static function loadEnv(string $root): void
  {
    if (file_exists($root . '/.env')) {
      Dotenv::createImmutable($root)->safeLoad();
    }
  }
}
