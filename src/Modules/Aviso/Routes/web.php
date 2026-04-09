<?php

use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Modules\Aviso\Controllers\AvisoController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$admin = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Rotas públicas — qualquer um pode ver avisos ativos
$router->get('/api/avisos',      [AvisoController::class, 'listar']);
$router->get('/api/avisos/{id}', [AvisoController::class, 'buscar']);

// Rotas admin — requer autenticação com nível admin_system
$router->get('/api/admin/avisos',         [AvisoController::class, 'listarTodos'], $admin);
$router->post('/api/admin/avisos',        [AvisoController::class, 'criar'],       $admin);
$router->put('/api/admin/avisos/{id}',    [AvisoController::class, 'atualizar'],   $admin);
$router->delete('/api/admin/avisos/{id}', [AvisoController::class, 'deletar'],     $admin);
