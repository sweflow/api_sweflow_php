<?php

namespace Src\Modules\Authenticador\Controllers;

use PDO;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Authenticador\Services\RateLimitService;
use Src\Modules\Authenticador\Services\AuthenticationService;

/**
 * Controller: SecurityController
 * 
 * Gerencia operações de segurança (rate limit, sessões, etc)
 */
class SecurityController
{
    private PDO $pdo;
    private RateLimitService $rateLimitService;
    private AuthenticationService $authService;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->rateLimitService = new RateLimitService($pdo);
        $this->authService = new AuthenticationService($pdo);
    }
    
    /**
     * Lista todas as sessões ativas do usuário
     * 
     * GET /api/auth/sessions
     * Requer autenticação
     */
    public function listarSessoes(Request $request): Response
    {
        $usuarioUuid = $request->usuarioUuid ?? null;
        
        if (!$usuarioUuid) {
            return Response::json([
                'error' => 'Usuário não autenticado',
            ], 401);
        }
        
        $sessoes = $this->authService->listarSessoesAtivas($usuarioUuid);
        
        return Response::json([
            'sessoes' => $sessoes,
            'total' => count($sessoes),
        ]);
    }
    
    /**
     * Revoga uma sessão específica
     * 
     * DELETE /api/auth/sessions/:uuid
     * Requer autenticação
     */
    public function revogarSessao(Request $request): Response
    {
        $usuarioUuid = $request->usuarioUuid ?? null;
        $sessaoUuid = $request->getParam('uuid');
        
        if (!$usuarioUuid) {
            return Response::json([
                'error' => 'Usuário não autenticado',
            ], 401);
        }
        
        if (!$sessaoUuid) {
            return Response::json([
                'error' => 'UUID da sessão não fornecido',
            ], 400);
        }
        
        $sucesso = $this->authService->revogarSessao($sessaoUuid, $usuarioUuid);
        
        if (!$sucesso) {
            return Response::json([
                'error' => 'Sessão não encontrada ou não pertence ao usuário',
            ], 404);
        }
        
        return Response::json([
            'message' => 'Sessão revogada com sucesso',
        ]);
    }
    
    /**
     * Revoga todas as sessões exceto a atual
     * 
     * POST /api/auth/sessions/revoke-others
     * Requer autenticação
     */
    public function revogarOutrasSessoes(Request $request): Response
    {
        $usuarioUuid = $request->usuarioUuid ?? null;
        $sessaoAtualUuid = $request->sessaoUuid ?? null;
        
        if (!$usuarioUuid) {
            return Response::json([
                'error' => 'Usuário não autenticado',
            ], 401);
        }
        
        // Busca todas as sessões
        $sessoes = $this->authService->listarSessoesAtivas($usuarioUuid);
        $revogadas = 0;
        
        foreach ($sessoes as $sessao) {
            if ($sessao['uuid'] !== $sessaoAtualUuid) {
                $this->authService->revogarSessao($sessao['uuid'], $usuarioUuid);
                $revogadas++;
            }
        }
        
        return Response::json([
            'message' => 'Outras sessões revogadas com sucesso',
            'sessoes_revogadas' => $revogadas,
        ]);
    }
    
    /**
     * Obtém estatísticas de rate limit do usuário
     * 
     * GET /api/auth/rate-limit
     * Requer autenticação
     */
    public function obterRateLimit(Request $request): Response
    {
        $usuarioUuid = $request->usuarioUuid ?? null;
        
        if (!$usuarioUuid) {
            return Response::json([
                'error' => 'Usuário não autenticado',
            ], 401);
        }
        
        $identifier = RateLimitService::obterIdentificador($usuarioUuid);
        $scope = $request->getQuery('scope') ?? 'authenticated';
        
        $stats = $this->rateLimitService->obterEstatisticas($identifier, $scope);
        
        if (!$stats) {
            return Response::json([
                'message' => 'Nenhum rate limit ativo',
            ]);
        }
        
        return Response::json($stats);
    }
    
    /**
     * Reseta rate limit (admin only)
     * 
     * POST /api/auth/rate-limit/reset
     * Body: { "identifier": "...", "scope": "..." }
     * Requer autenticação de admin
     */
    public function resetarRateLimit(Request $request): Response
    {
        $data = $request->body ?? [];
        
        if (!isset($data['identifier'])) {
            return Response::json([
                'error' => 'Identificador não fornecido',
            ], 400);
        }
        
        $scope = $data['scope'] ?? null;
        $count = $this->rateLimitService->resetar($data['identifier'], $scope);
        
        return Response::json([
            'message' => 'Rate limit resetado',
            'registros_removidos' => $count,
        ]);
    }
    
    /**
     * Limpa rate limits expirados (admin only)
     * 
     * POST /api/auth/rate-limit/cleanup
     * Requer autenticação de admin
     */
    public function limparRateLimits(Request $request): Response
    {
        $count = $this->rateLimitService->limparExpirados();
        
        return Response::json([
            'message' => 'Limpeza concluída',
            'registros_removidos' => $count,
        ]);
    }
}
