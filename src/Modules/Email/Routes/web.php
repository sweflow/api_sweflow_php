<?php

use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$protected = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Disparo de e-mail personalizado
$router->post('/api/email/custom',                    [\Src\Modules\Email\Controllers\EmailController::class, 'enviar'],         $protected);

// Histórico
$router->get('/api/email/history',                    [\Src\Modules\Email\Controllers\EmailController::class, 'listarHistorico'], $protected);
$router->get('/api/email/history/{id}',               [\Src\Modules\Email\Controllers\EmailController::class, 'detalheHistorico'], $protected);
$router->delete('/api/email/history/{id}',            [\Src\Modules\Email\Controllers\EmailController::class, 'deletarHistorico'], $protected);
$router->post('/api/email/history/{id}/resend',       [\Src\Modules\Email\Controllers\EmailController::class, 'reenviar'],       $protected);
