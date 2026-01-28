<?php

declare(strict_types=1);

namespace App\App\Http\Middleware;

use App\App\Http\Request;
use App\App\Http\Response;

final class IdempotencyMiddleware
{
  public function __construct(private array $config) {}

  public function handle(Request $req, Response $res, callable $next): Response
  {
    // We’ll enforce idempotency on POST transfer endpoints later.
    // For now: leave hook in place so architecture is ready.
    return $next($req, $res);
  }
}
