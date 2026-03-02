<?php

use Src\Middlewares\RouteProtectionMiddleware;
use Src\Modules\Usuario\Controllers\UsuarioController;
use Src\Routes\Route;

$protected = [RouteProtectionMiddleware::class];

Route::post('/api/criar/usuario', [UsuarioController::class, 'criar']);
Route::get('/api/usuarios', [UsuarioController::class, 'listar'], $protected);
Route::get('/api/usuario/{uuid}', [UsuarioController::class, 'buscar'], $protected);
Route::put('/api/usuario/atualizar/{uuid}', [UsuarioController::class, 'atualizar'], $protected);
Route::delete('/api/usuario/deletar/{uuid}', [UsuarioController::class, 'deletar'], $protected);
Route::patch('/api/usuario/{uuid}/desativar', [UsuarioController::class, 'desativar'], $protected);
Route::patch('/api/usuario/{uuid}/ativar', [UsuarioController::class, 'ativar'], $protected);
