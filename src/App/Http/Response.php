<?php

declare(strict_types=1);

namespace App\App\Http;

final class Response
{
  private int $status = 200;
  private array $headers = ['content-type' => 'application/json; charset=utf-8'];
  private mixed $payload = null;

  public function status(int $code): self
  {
    $this->status = $code;
    return $this;
  }

  public function json(array $data, int $status = 200): self
  {
    $this->status = $status;
    $this->payload = $data;
    return $this;
  }

  public function send(): void
  {
    http_response_code($this->status);
    foreach ($this->headers as $k => $v) header($k . ': ' . $v);
    echo json_encode($this->payload ?? ['ok' => true], JSON_UNESCAPED_SLASHES);
  }
}
