<?php

use src\Middlewares\RouteProtectionMiddleware;
use src\Routes\Route;
use src\Modules\Usuario\Controllers\UsuarioController;

// Rota para criar um novo usuário
Route::post('/api/criar/usuario', [UsuarioController::class, 'criar'], [
]);

// Rota para listar usuários
Route::get('/api/usuarios', [UsuarioController::class, 'listar'], [
    RouteProtectionMiddleware::class => []
]);

// Rota para buscar um usuário por UUID
Route::get('/api/usuario/{uuid}', [UsuarioController::class, 'buscar'], [
]);

// Rota para atualizar um usuário por UUID
Route::put('/api/usuario/atualizar/{uuid}', [UsuarioController::class, 'atualizar'], [
]);

// Rota para deletar um usuário por UUID
Route::delete('/api/usuario/deletar/{uuid}', [UsuarioController::class, 'deletar'], [
]);

// Rota para desativar um usuário por UUID
Route::patch('/api/usuario/{uuid}/desativar', [UsuarioController::class, 'desativar'], [
]);

// Rota para ativar um usuário por UUID
Route::patch('/api/usuario/{uuid}/ativar', [UsuarioController::class, 'ativar'], [
]);