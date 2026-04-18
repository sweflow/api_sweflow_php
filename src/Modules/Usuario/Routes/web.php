<?php

use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Middlewares\CircuitBreakerMiddleware;
use Src\Modules\Usuario\Controllers\UsuarioController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Middlewares
$adminProtected = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];
$userProtected  = [AuthHybridMiddleware::class];

// Circuit breaker para rotas que dependem de DB
$dbCircuit = [CircuitBreakerMiddleware::class, ['service' => 'database', 'threshold' => 5, 'cooldown' => 20]];

// Rate limits
$registroRateLimit  = [RateLimitMiddleware::class, ['limit' => 5,  'window' => 60,  'key' => 'usuario.registro']];
$reenvioRateLimit   = [RateLimitMiddleware::class, ['limit' => 3,  'window' => 300, 'key' => 'usuario.reenvio']];
$perfilRateLimit    = [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60,  'key' => 'usuario.perfil']];
$verificaRateLimit  = [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60,  'key' => 'usuario.verifica']];

// Registro público de usuário
$router->post('/api/criar/usuario', [UsuarioController::class, 'criar'], [$registroRateLimit, $dbCircuit]);
$router->post('/api/registrar',     [UsuarioController::class, 'criar'], [$registroRateLimit, $dbCircuit]);

// Reenvio de e-mail de verificação (público, sem autenticação)
$router->post('/api/auth/reenviar-verificacao', [UsuarioController::class, 'reenviarVerificacaoEmail'], [$reenvioRateLimit]);

// Gerenciamento de usuários (admin)
$router->get('/api/usuarios', [UsuarioController::class, 'listar'], $adminProtected);
$router->get('/api/usuario/{uuid}', [UsuarioController::class, 'buscar'], $adminProtected);
$router->put('/api/usuario/atualizar/{uuid}', [UsuarioController::class, 'atualizar'], $adminProtected);
$router->delete('/api/usuario/deletar/{uuid}', [UsuarioController::class, 'deletar'], $adminProtected);
$router->patch('/api/usuario/{uuid}/desativar', [UsuarioController::class, 'desativar'], $adminProtected);
$router->patch('/api/usuario/{uuid}/ativar', [UsuarioController::class, 'ativar'], $adminProtected);

// Perfil do usuário autenticado
$router->get('/api/perfil', [UsuarioController::class, 'perfil'], $userProtected);
$router->put('/api/perfil', [UsuarioController::class, 'atualizarPerfil'], $userProtected);
$router->put('/api/perfil/email', [UsuarioController::class, 'alterarEmail'], $userProtected);
$router->put('/api/perfil/senha', [UsuarioController::class, 'alterarSenha'], $userProtected);
$router->post('/api/perfil/upload', [UsuarioController::class, 'uploadProfileImage'], $userProtected);
$router->delete('/api/perfil', [UsuarioController::class, 'deletarMinhaConta'], $userProtected);

// Perfil público
$router->get('/api/perfil/{username}', [UsuarioController::class, 'buscarPorUsername'], [$perfilRateLimit]);
$router->get('/perfil/{username}',     [UsuarioController::class, 'exibirPerfilHtml'],  [$perfilRateLimit]);

// Verificação de e-mail
$router->post('/api/usuarios/{uuid}/enviar-verificacao-email', [UsuarioController::class, 'enviarVerificacaoEmail'],        $adminProtected);
$router->post('/api/usuarios/enviar-verificacao-email',        [UsuarioController::class, 'enviarVerificacaoEmailPorEmail'], $adminProtected);
$router->get('/api/usuarios/verificar-email-status',           [UsuarioController::class, 'verificarEmailStatus'],          $adminProtected);
$router->post('/api/usuarios/verificar-email/{token}',         [UsuarioController::class, 'verificarEmail'],                [$verificaRateLimit]);
