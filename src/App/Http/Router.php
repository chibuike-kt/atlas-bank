<?php

declare(strict_types=1);

namespace App\App\Http;

final class Router
{
  private array $routes = [];

  public function get(string $path, callable|array $handler): void
  {
    $this->routes['GET'][$path] = $handler;
  }
  public function post(string $path, callable|array $handler): void
  {
    $this->routes['POST'][$path] = $handler;
  }

  public function dispatch(Request $req, Response $res, array $config): Response
  {
    $handler = $this->routes[$req->method][$req->path] ?? null;
    if (!$handler) return $res->json(['error' => 'not_found'], 404);

    if (is_array($handler)) {
      [$class, $method] = $handler;
      $obj = new $class($config);
      return $obj->$method($req, $res);
    }

    return $handler($req, $res);
  }
}
