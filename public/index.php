<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\App\Bootstrap;
use App\App\Http\Router;
use App\App\Http\Request;
use App\App\Http\Response;
use App\App\Http\MiddlewareStack;
use App\App\Http\Controllers\HealthController;
use App\App\Http\Controllers\AuthController;
use App\App\Http\Middleware\RateLimitMiddleware;
use App\App\Http\Middleware\IdempotencyMiddleware;
use App\App\Http\Controllers\TransferController;



$root = dirname(__DIR__);
Bootstrap::loadEnv($root);

$config = require $root . '/src/App/Config/config.php';

$request = Request::fromGlobals();
$response = new Response();

$router = new Router();

/** Routes */
$router->get('/health', [HealthController::class, 'handle']);

$router->post('/auth/register', [AuthController::class, 'register']);

$router->post('/auth/login', [AuthController::class, 'login']);

$router->post('/transfers/internal', [TransferController::class, 'internal']);

$router->post('/transfers/{id}/reverse', [TransferController::class, 'reverse']);


/** Global middleware */
$stack = new MiddlewareStack([
  new RateLimitMiddleware(),
  // Auth must run before Idempotency (idempotency is per-user)
  // We'll apply auth globally for now (simple); later we’ll do per-route middleware.
  new \App\App\Http\Middleware\AuthMiddleware($config),
  new IdempotencyMiddleware($config),
]);


$stack->handle($request, $response, function ($req, $res) use ($router, $config) {
  return $router->dispatch($req, $res, $config);
});

$response->send();
