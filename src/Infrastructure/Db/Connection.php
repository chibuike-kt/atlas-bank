<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDO;

final class Connection
{
  public static function pdo(array $config): PDO
  {
    $pdo = new PDO(
      $config['dsn'],
      $config['user'],
      $config['pass'],
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]
    );
    return $pdo;
  }
}
