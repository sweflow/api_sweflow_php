<?php

use Src\Kernel\Middlewares\RouteProtectionMiddleware;
use Src\Modules\Usuario\Controllers\UsuarioController;

$protected = [RouteProtectionMiddleware::class];

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$router->post('/api/criar/usuario', [UsuarioController::class, 'criar']);
$router->get('/api/usuarios', [UsuarioController::class, 'listar'], $protected);
$router->get('/api/usuario/{uuid}', [UsuarioController::class, 'buscar'], $protected);
$router->put('/api/usuario/atualizar/{uuid}', [UsuarioController::class, 'atualizar'], $protected);
$router->delete('/api/usuario/deletar/{uuid}', [UsuarioController::class, 'deletar'], $protected);
$router->patch('/api/usuario/{uuid}/desativar', [UsuarioController::class, 'desativar'], $protected);
$router->patch('/api/usuario/{uuid}/ativar', [UsuarioController::class, 'ativar'], $protected);
