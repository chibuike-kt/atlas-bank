<?php

declare(strict_types=1);

namespace App\App\Http\Controllers;

use App\App\Http\Request;
use App\App\Http\Response;

final class HealthController
{
  public function __construct(private array $config) {}

  public function handle(Request $req, Response $res): Response
  {
    return $res->json(['ok' => true, 'env' => $this->config['env']], 200);
  }
}
