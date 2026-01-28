<?php

declare(strict_types=1);

namespace App\App\Security;

use Firebase\JWT\JWT as FirebaseJWT;

final class Jwt
{
  public static function issue(array $config, string $userId): string
  {
    $now = time();
    $ttl = (int)$config['jwt']['ttl'];

    $payload = [
      'iss' => $config['jwt']['iss'],
      'aud' => $config['jwt']['aud'],
      'iat' => $now,
      'exp' => $now + $ttl,
      'sub' => $userId,
    ];

    return FirebaseJWT::encode($payload, $config['app_key'], 'HS256');
  }
}
