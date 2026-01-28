<?php
declare(strict_types=1);

return [
  'env' => getenv('APP_ENV') ?: 'local',
  'app_key' => getenv('APP_KEY') ?: '',
  'db' => [
    'dsn' => getenv('DB_DSN') ?: '',
    'user' => getenv('DB_USER') ?: '',
    'pass' => getenv('DB_PASS') ?: '',
  ],
  'jwt' => [
    'iss' => getenv('JWT_ISS') ?: 'atlas-bank',
    'aud' => getenv('JWT_AUD') ?: 'atlas-bank-users',
    'ttl' => (int)(getenv('JWT_TTL_SECONDS') ?: 900),
  ],
];
