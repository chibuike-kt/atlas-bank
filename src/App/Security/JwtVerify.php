<?php

declare(strict_types=1);

namespace App\App\Security;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;

final class JwtVerify
{
  public static function verify(array $config, string $token): array
  {
    $decoded = FirebaseJWT::decode($token, new Key($config['app_key'], 'HS256'));
    // Return as array for convenience
    return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
  }
}
