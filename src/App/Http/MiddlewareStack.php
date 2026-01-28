<?php

declare(strict_types=1);

namespace App\App\Http;

final class MiddlewareStack
{
  /** @var array<int, object> */
  private array $middleware;

  public function __construct(array $middleware)
  {
    $this->middleware = $middleware;
  }

  public function handle(Request $req, Response $res, callable $last): Response
  {
    $runner = array_reduce(
      array_reverse($this->middleware),
      fn($next, $mw) => fn($r, $s) => $mw->handle($r, $s, $next),
      fn($r, $s) => $last($r, $s)
    );

    return $runner($req, $res);
  }
}
