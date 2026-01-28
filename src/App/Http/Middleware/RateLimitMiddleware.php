<?php

declare(strict_types=1);

namespace App\App\Http\Middleware;

use App\App\Http\Request;
use App\App\Http\Response;

final class RateLimitMiddleware
{
  public function handle(Request $req, Response $res, callable $next): Response
  {
    // Placeholder: next step is Redis-backed counters keyed by IP + route + user.
    return $next($req, $res);
  }
}
