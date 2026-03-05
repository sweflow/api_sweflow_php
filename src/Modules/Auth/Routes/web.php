<?php

use Src\Modules\Auth\Controllers\AuthController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;

$protected = [AuthHybridMiddleware::class];

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Autenticação de usuário
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/login', [AuthController::class, 'loginPublic']);
$router->post('/api/auth/recuperacao-senha', [AuthController::class, 'solicitarRecuperacaoSenha']);
$router->post('/api/auth/resetar-senha', [AuthController::class, 'resetarSenha']);
$router->get('/api/auth/me', [AuthController::class, 'me'], $protected);
$router->post('/api/auth/logout', [AuthController::class, 'logout'], $protected);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->get('/api/auth/email-verification', [AuthController::class, 'emailVerificationPolicy'], $protected);
$router->post('/api/auth/email-verification', [AuthController::class, 'emailVerificationPolicy'], $protected);
$router->get('/api/auth/verify-email', [AuthController::class, 'verifyEmail']);
