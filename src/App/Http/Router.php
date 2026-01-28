<?php

declare(strict_types=1);

namespace App\App\Http;

final class Router
{
  /** @var array<string, array<int, array{pattern:string, handler:callable|array, vars:array<int,string> }>> */
  private array $routes = [];

  public function get(string $path, callable|array $handler): void
  {
    $this->add('GET', $path, $handler);
  }
  public function post(string $path, callable|array $handler): void
  {
    $this->add('POST', $path, $handler);
  }

  private function add(string $method, string $path, callable|array $handler): void
  {
    // Convert /transfers/{id}/reverse to regex
    $vars = [];
    $pattern = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$vars) {
      $vars[] = $m[1];
      return '([^/]+)';
    }, $path);

    $pattern = '#^' . $pattern . '$#';

    $this->routes[$method][] = [
      'pattern' => $pattern,
      'handler' => $handler,
      'vars' => $vars,
    ];
  }

  public function dispatch(Request $req, Response $res, array $config): Response
  {
    $candidates = $this->routes[$req->method] ?? [];
    foreach ($candidates as $r) {
      if (preg_match($r['pattern'], $req->path, $m)) {
        array_shift($m);
        $params = [];
        foreach ($r['vars'] as $i => $name) $params[$name] = $m[$i] ?? null;

        $handler = $r['handler'];

        if (is_array($handler)) {
          [$class, $method] = $handler;
          $obj = new $class($config);
          return $obj->$method($req, $res, $params);
        }

        return $handler($req, $res, $params);
      }
    }

    return $res->json(['error' => 'not_found'], 404);
  }
}
