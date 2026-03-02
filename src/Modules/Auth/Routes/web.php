<?php

use Src\Modules\Auth\Controllers\AuthController;
use Src\Routes\Route;
use Src\Middlewares\AuthHybridMiddleware;

// Autenticação de usuário
Route::post('/api/auth/login', [AuthController::class, 'login']);
Route::get('/api/auth/me', [AuthController::class, 'me'], [
	AuthHybridMiddleware::class
]);
Route::post('/api/auth/logout', [AuthController::class, 'logout'], [
	AuthHybridMiddleware::class
]);
Route::post('/api/auth/refresh', [AuthController::class, 'refresh']);
