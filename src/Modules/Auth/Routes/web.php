<?php

use Src\Modules\Auth\Controllers\AuthController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\ApiTokenMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Middlewares\CircuitBreakerMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$protected    = [AuthHybridMiddleware::class];
$apiProtected = [ApiTokenMiddleware::class];

// Circuit breaker para rotas que dependem de DB
$dbCircuit = [CircuitBreakerMiddleware::class, ['service' => 'database', 'threshold' => 5, 'cooldown' => 20]];

// Rate limits: login → 10/min, recuperação → 5/min, refresh → 20/min
$loginRateLimit    = [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60,  'key' => 'auth.login',    'user_limit' => 5]];
$recoveryRateLimit = [RateLimitMiddleware::class, ['limit' => 5,  'window' => 60,  'key' => 'auth.recovery', 'user_limit' => 3]];
$refreshRateLimit  = [RateLimitMiddleware::class, ['limit' => 20, 'window' => 60,  'key' => 'auth.refresh',  'user_limit' => 10]];

// Autenticação
$router->post('/api/auth/login', [AuthController::class, 'login'],       [$loginRateLimit, $dbCircuit]);
$router->post('/api/login',      [AuthController::class, 'loginPublic'], [$loginRateLimit, $dbCircuit]);

// Recuperação de senha
$router->post('/api/auth/recuperacao-senha',         [AuthController::class, 'solicitarRecuperacaoSenha'], [$recoveryRateLimit]);
$router->post('/api/auth/resetar-senha',             [AuthController::class, 'resetarSenha'],              [$recoveryRateLimit]);
$router->post('/api/recuperar-senha',                [AuthController::class, 'solicitarRecuperacaoSenha'], [$recoveryRateLimit]);
$router->post('/api/recuperar-senha/confirmar',      [AuthController::class, 'resetarSenha'],              [$recoveryRateLimit]);
$router->get('/api/recuperar-senha/validar/{token}', [AuthController::class, 'validarTokenRecuperacao'],   [$recoveryRateLimit]);

// Sessão autenticada
$router->get('/api/auth/me',      [AuthController::class, 'me'],      $protected);
$router->post('/api/auth/logout', [AuthController::class, 'logout'],  $protected);
$router->post('/api/auth/refresh',[AuthController::class, 'refresh'], [$refreshRateLimit]);

// Verificação de e-mail
$router->get('/api/auth/email-verification',  [AuthController::class, 'emailVerificationPolicy'], $protected);
$router->post('/api/auth/email-verification', [AuthController::class, 'emailVerificationPolicy'], $protected);
$router->get('/api/auth/verify-email', [AuthController::class, 'verifyEmail'], [$recoveryRateLimit]);
