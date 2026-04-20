<?php

use Src\Modules\Estoque\Controllers\EstoqueController;
use Src\Modules\Estoque\Middlewares\EstoqueMiddleware;

use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$auth  = [AuthHybridMiddleware::class, EstoqueMiddleware::class];
$admin = [AuthHybridMiddleware::class, EstoqueMiddleware::class, AdminOnlyMiddleware::class];

// Leitura
$router->get('/api/estoque',      [EstoqueController::class, 'listar'], $auth);
$router->get('/api/estoque/{id}', [EstoqueController::class, 'buscar'], $auth);

// Escrita
$router->post('/api/estoque',     [EstoqueController::class, 'criar'], $auth);
$router->put('/api/estoque/{id}', [EstoqueController::class, 'atualizar'], $auth);

// Admin
$router->delete('/api/estoque/{id}', [EstoqueController::class, 'deletar'], $auth);