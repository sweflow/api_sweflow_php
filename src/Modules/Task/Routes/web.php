<?php

use Task\Controllers\TaskController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$auth  = [AuthHybridMiddleware::class];
$admin = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Leitura
$router->get('/api/task',          [TaskController::class, 'listar'],    $auth);
$router->get('/api/task/{id}',     [TaskController::class, 'buscar'],    $auth);

// Escrita
$router->post('/api/task',         [TaskController::class, 'criar'],     $auth);
$router->put('/api/task/{id}',     [TaskController::class, 'atualizar'], $auth);

// Admin
$router->delete('/api/task/{id}',  [TaskController::class, 'deletar'],   $admin);
