<?php

use Src\Modules\LinkEncurtador\Controllers\LinkController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$auth  = [AuthHybridMiddleware::class];
$admin = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Rate limit para criação: 30 links por minuto por usuário
$createLimit = [
    AuthHybridMiddleware::class,
    [RateLimitMiddleware::class, ['limit' => 30, 'window' => 60, 'key' => 'links.create']],
];

// ── API autenticada ───────────────────────────────────────────────────────────
$router->get('/api/links',                        [LinkController::class, 'listar'],       $auth);
$router->get('/api/links/stats',                  [LinkController::class, 'stats'],        $auth);
$router->get('/api/links/my-limit',               [LinkController::class, 'myLimit'],      $auth);
$router->get('/api/links/{id}',                   [LinkController::class, 'buscar'],       $auth);
$router->get('/api/links/{id}/analytics',         [LinkController::class, 'analytics'],    $auth);
$router->post('/api/links',                       [LinkController::class, 'criar'],        $createLimit);
$router->put('/api/links/{id}',                   [LinkController::class, 'atualizar'],    $auth);
$router->delete('/api/links/{id}',                [LinkController::class, 'deletar'],      $auth);

// ── Admin: gerenciar limites por usuário ──────────────────────────────────────
$router->get('/api/links/user-limit/{userId}',    [LinkController::class, 'getUserLimit'], $admin);
$router->put('/api/links/user-limit/{userId}',    [LinkController::class, 'setUserLimit'], $admin);

// ── Redirect público — sem autenticação ──────────────────────────────────────
$redirectLimit = [
    [RateLimitMiddleware::class, ['limit' => 120, 'window' => 60, 'key' => 'links.redirect']],
];
$router->get('/r/{alias}', [LinkController::class, 'redirect'], $redirectLimit);
