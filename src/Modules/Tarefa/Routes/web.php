<?php

use Src\Modules\Tarefa\Controllers\TarefaController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$auth = [AuthHybridMiddleware::class];

$router->get('/api/tarefa',       [TarefaController::class, 'listar'],    $auth);
$router->post('/api/tarefa',      [TarefaController::class, 'criar'],     $auth);
$router->get('/api/tarefa/{id}',  [TarefaController::class, 'buscar'],    $auth);
$router->put('/api/tarefa/{id}',  [TarefaController::class, 'atualizar'], $auth);
$router->delete('/api/tarefa/{id}', [TarefaController::class, 'deletar'], $auth);