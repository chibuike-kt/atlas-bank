<?php

declare(strict_types=1);

namespace App\App\Http;

final class Request
{
  public function __construct(
    public readonly string $method,
    public readonly string $path,
    public readonly array $headers,
    public readonly array $query,
    public readonly array $body
  ) {}

  public static function fromGlobals(): self
  {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    $headers = [];
    foreach ($_SERVER as $k => $v) {
      if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace('_', '-', strtolower(substr($k, 5)));
        $headers[$name] = (string)$v;
      }
    }

    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $body = is_array($json) ? $json : [];

    return new self($method, $path, $headers, $_GET ?? [], $body);
  }

  public function header(string $name): ?string
  {
    $key = strtolower($name);
    return $this->headers[$key] ?? null;
  }
}
