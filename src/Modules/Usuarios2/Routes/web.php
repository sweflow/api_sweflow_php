<?php

/**
 * Rotas do Módulo Usuarios2
 * 
 * Gerenciamento de usuários (CRUD)
 * 
 * Nota: Rotas de autenticação foram movidas para o módulo Authenticador
 */

use Src\Modules\Usuarios2\Controllers\Usuario2Controller;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// ═══════════════════════════════════════════════════════════
// USUÁRIOS (Protegidas - Requer Auth + Permission Middleware)
// ═══════════════════════════════════════════════════════════

// Listar usuários
$router->get('/api/usuarios2', [Usuario2Controller::class, 'listar']);

// Buscar usuário
$router->get('/api/usuarios2/{uuid}', [Usuario2Controller::class, 'buscar']);

// Criar usuário
$router->post('/api/usuarios2', [Usuario2Controller::class, 'criar']);

// Atualizar usuário
$router->put('/api/usuarios2/{uuid}', [Usuario2Controller::class, 'atualizar']);

// Deletar usuário
$router->delete('/api/usuarios2/{uuid}', [Usuario2Controller::class, 'deletar']);

// Bloquear usuário
$router->post('/api/usuarios2/{uuid}/bloquear', [Usuario2Controller::class, 'bloquear']);

// Desbloquear usuário
$router->post('/api/usuarios2/{uuid}/desbloquear', [Usuario2Controller::class, 'desbloquear']);

// Estatísticas
$router->get('/api/usuarios2/stats', [Usuario2Controller::class, 'estatisticas']);
