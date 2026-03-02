<?php

use Src\Modules\Auth\Controllers\AuthController;
use Src\Routes\Route;
use Src\Middlewares\AuthHybridMiddleware;

$protected = [AuthHybridMiddleware::class];

// Autenticação de usuário
Route::post('/api/auth/login', [AuthController::class, 'login']);
Route::get('/api/auth/me', [AuthController::class, 'me'], $protected);
Route::post('/api/auth/logout', [AuthController::class, 'logout'], $protected);
Route::post('/api/auth/refresh', [AuthController::class, 'refresh']);
