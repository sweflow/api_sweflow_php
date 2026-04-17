<?php

use Src\Modules\LinkEncurtador\Controllers\AuthController;
use Src\Modules\LinkEncurtador\Controllers\LinkController;
use Src\Modules\LinkEncurtador\Middlewares\LinkAuthMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Auth própria do encurtador (usa link_usuarios, não usuarios do kernel)
$linkAuth  = [LinkAuthMiddleware::class];
$adminAuth = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

$authRateLimit = [
    [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60, 'key' => 'link.auth']],
];
$createLimit = [
    LinkAuthMiddleware::class,
    [RateLimitMiddleware::class, ['limit' => 30, 'window' => 60, 'key' => 'links.create']],
];

// ── Auth do encurtador ────────────────────────────────────────────────────────
$router->post('/api/link-auth/register', [AuthController::class, 'register'], $authRateLimit);
$router->post('/api/link-auth/login',    [AuthController::class, 'login'],    $authRateLimit);
$router->post('/api/link-auth/google',   [AuthController::class, 'googleAuth'], $authRateLimit);
$router->get('/api/link-auth/me',        [AuthController::class, 'me'],       $linkAuth);
$router->put('/api/link-auth/profile',   [AuthController::class, 'updateProfile'], $linkAuth);
$router->put('/api/link-auth/password',  [AuthController::class, 'changePassword'], $linkAuth);
$router->post('/api/link-auth/logout',   [AuthController::class, 'logout'],   $linkAuth);

// ── API de links (autenticada com auth própria) ───────────────────────────────
$router->get('/api/links',                        [LinkController::class, 'listar'],       $linkAuth);
$router->get('/api/links/stats',                  [LinkController::class, 'stats'],        $linkAuth);
$router->get('/api/links/my-limit',               [LinkController::class, 'myLimit'],      $linkAuth);
$router->get('/api/links/{id}',                   [LinkController::class, 'buscar'],       $linkAuth);
$router->get('/api/links/{id}/analytics',         [LinkController::class, 'analytics'],    $linkAuth);
$router->post('/api/links',                       [LinkController::class, 'criar'],        $createLimit);
$router->put('/api/links/{id}',                   [LinkController::class, 'atualizar'],    $linkAuth);
$router->delete('/api/links/{id}',                [LinkController::class, 'deletar'],      $linkAuth);

// ── Admin: gerenciar limites (usa auth do kernel — admin_system) ──────────────
$router->get('/api/links/user-limit/{userId}',    [LinkController::class, 'getUserLimit'], $adminAuth);
$router->put('/api/links/user-limit/{userId}',    [LinkController::class, 'setUserLimit'], $adminAuth);
$router->put('/api/links/user-limit/all',         [LinkController::class, 'setAllUsersLimit'], $adminAuth);

// ── Redirect público ──────────────────────────────────────────────────────────
$redirectLimit = [
    [RateLimitMiddleware::class, ['limit' => 120, 'window' => 60, 'key' => 'links.redirect']],
];
$router->get('/r/{alias}', [LinkController::class, 'redirect'], $redirectLimit);
