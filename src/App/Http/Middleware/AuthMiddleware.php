<?php

declare(strict_types=1);

namespace App\App\Http\Middleware;

use App\App\Http\Request;
use App\App\Http\Response;
use App\App\Security\JwtVerify;

final class AuthMiddleware
{

  public function __construct(private array $config) {}


  public function handle(Request $req, Response $res, callable $next): Response
  {
    $public = [
      'GET /health',
      'POST /auth/register',
      'POST /auth/login',
    ];
    if (in_array($req->method . ' ' . $req->path, $public, true)) {
      return $next($req, $res);
    }

    $auth = $req->header('authorization') ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
      return $res->json(['error' => 'missing_auth'], 401);
    }

    try {
      $claims = JwtVerify::verify($this->config, $m[1]);
    } catch (\Throwable $e) {
      return $res->json(['error' => 'invalid_token'], 401);
    }

    // attach claims to request (simple approach: put into headers bag)
    $req->headers['x-auth-sub'] = (string)($claims['sub'] ?? '');
    return $next($req, $res);
  }
}
