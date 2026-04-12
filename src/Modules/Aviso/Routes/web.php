<?php

use Src\Kernel\Auth;
use Src\Modules\Aviso\Controllers\AvisoController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Rotas públicas — qualquer um pode ver avisos ativos
$router->get('/api/avisos',      [AvisoController::class, 'listar']);
$router->get('/api/avisos/{id}', [AvisoController::class, 'buscar']);

// Rotas admin — requer admin_system
$router->get('/api/admin/avisos',         [AvisoController::class, 'listarTodos'], Auth::admin());
$router->post('/api/admin/avisos',        [AvisoController::class, 'criar'],       Auth::admin());
$router->put('/api/admin/avisos/{id}',    [AvisoController::class, 'atualizar'],   Auth::admin());
$router->delete('/api/admin/avisos/{id}', [AvisoController::class, 'deletar'],     Auth::admin());
