<?php

/**
 * Rotas do Módulo Authenticador
 * 
 * Define todas as rotas relacionadas a autenticação, tokens e segurança
 */

use Src\Modules\Authenticador\Controllers\TokenController;
use Src\Modules\Authenticador\Controllers\SecurityController;
use Src\Modules\Authenticador\Controllers\OAuth2Controller;
use Src\Modules\Authenticador\Controllers\LoginController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// ============================================
// ROTAS PÚBLICAS - Login/Logout (Usuarios2)
// ============================================

// Login com email e senha (usa tabela usuarios2)
$router->post('/api/auth2/login', [LoginController::class, 'login']);

// Logout (revoga token)
$router->post('/api/auth2/logout', [LoginController::class, 'logout']);

// ============================================
// ROTAS PÚBLICAS - OAuth2
// ============================================

// Redirecionar para login do Google
$router->get('/api/auth/google', [OAuth2Controller::class, 'redirectToGoogle']);

// Callback do Google
$router->get('/api/auth/google/callback', [OAuth2Controller::class, 'handleGoogleCallback']);

// ============================================
// ROTAS PÚBLICAS - Token Management
// ============================================

// Validar token
$router->post('/api/auth/token/validate', [TokenController::class, 'validar']);

// Renovar token (refresh)
$router->post('/api/auth/token/refresh', [TokenController::class, 'renovar']);

// ============================================
// ROTAS AUTENTICADAS - Token Management
// ============================================

// Listar tokens do usuário
$router->get('/api/auth/tokens', [TokenController::class, 'listar']);

// Revogar um token específico
$router->post('/api/auth/token/revoke', [TokenController::class, 'revogar']);

// Revogar todos os tokens
$router->post('/api/auth/tokens/revoke-all', [TokenController::class, 'revogarTodos']);

// ============================================
// ROTAS AUTENTICADAS - Session Management
// ============================================

// Listar sessões ativas
$router->get('/api/auth/sessions', [SecurityController::class, 'listarSessoes']);

// Revogar uma sessão específica
$router->delete('/api/auth/sessions/{uuid}', [SecurityController::class, 'revogarSessao']);

// Revogar outras sessões (exceto a atual)
$router->post('/api/auth/sessions/revoke-others', [SecurityController::class, 'revogarOutrasSessoes']);

// ============================================
// ROTAS AUTENTICADAS - Rate Limit Info
// ============================================

// Obter informações de rate limit
$router->get('/api/auth/rate-limit', [SecurityController::class, 'obterRateLimit']);

// ============================================
// ROTAS AUTENTICADAS - OAuth2 Management
// ============================================

// Vincular conta Google
$router->post('/api/auth/google/link', [OAuth2Controller::class, 'linkGoogleAccount']);

// Desvincular conta Google
$router->delete('/api/auth/google/unlink', [OAuth2Controller::class, 'unlinkGoogleAccount']);

// ============================================
// ROTAS ADMIN - Maintenance
// ============================================

// Limpar tokens expirados
$router->post('/api/auth/tokens/cleanup', [TokenController::class, 'limpar']);

// Resetar rate limit de um usuário
$router->post('/api/auth/rate-limit/reset', [SecurityController::class, 'resetarRateLimit']);

// Limpar rate limits expirados
$router->post('/api/auth/rate-limit/cleanup', [SecurityController::class, 'limparRateLimits']);
