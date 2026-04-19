<?php

namespace Src\Modules\Authenticador\Controllers;

use PDO;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Authenticador\Services\TokenService;

/**
 * Controller: TokenController
 * 
 * Gerencia operações relacionadas a tokens JWT
 */
class TokenController
{
    private PDO $pdo;
    private TokenService $tokenService;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->tokenService = new TokenService($pdo);
    }
    
    /**
     * Valida um token
     * 
     * POST /api/auth/token/validate
     * Body: { "token": "..." }
     */
    public function validar(Request $request): Response
    {
        $data = $request->body ?? [];
        
        if (!isset($data['token'])) {
            return Response::json([
                'error' => 'Token não fornecido',
            ], 400);
        }
        
        $payload = $this->tokenService->validarToken($data['token']);
        
        if (!$payload) {
            return Response::json([
                'error' => 'Token inválido ou expirado',
                'valid' => false,
            ], 401);
        }
        
        return Response::json([
            'valid' => true,
            'payload' => $payload,
        ]);
    }
    
    /**
     * Renova um access token usando refresh token
     * 
     * POST /api/auth/token/refresh
     * Body: { "refresh_token": "..." }
     */
    public function renovar(Request $request): Response
    {
        $data = $request->body ?? [];
        
        if (!isset($data['refresh_token'])) {
            return Response::json([
                'error' => 'Refresh token não fornecido',
            ], 400);
        }
        
        $novoToken = $this->tokenService->renovarToken($data['refresh_token']);
        
        if (!$novoToken) {
            return Response::json([
                'error' => 'Refresh token inválido ou expirado',
            ], 401);
        }
        
        return Response::json($novoToken);
    }
    
    /**
     * Revoga um token
     * 
     * POST /api/auth/token/revoke
     * Body: { "token": "...", "motivo": "..." }
     * Requer autenticação
     */
    public function revogar(Request $request): Response
    {
        $data = $request->body ?? [];
        
        if (!isset($data['token'])) {
            return Response::json([
                'error' => 'Token não fornecido',
            ], 400);
        }
        
        $motivo = $data['motivo'] ?? 'Revogado pelo usuário';
        $sucesso = $this->tokenService->revogarToken($data['token'], $motivo);
        
        if (!$sucesso) {
            return Response::json([
                'error' => 'Token não encontrado',
            ], 404);
        }
        
        return Response::json([
            'message' => 'Token revogado com sucesso',
        ]);
    }
    
    /**
     * Lista todos os tokens do usuário autenticado
     * 
     * GET /api/auth/tokens
     * Requer autenticação
     */
    public function listar(Request $request): Response
    {
        $usuarioUuid = $request->usuarioUuid ?? null;
        
        if (!$usuarioUuid) {
            return Response::json([
                'error' => 'Usuário não autenticado',
            ], 401);
        }
        
        $apenasValidos = $request->getQuery('apenas_validos') === 'true';
        $tokens = $this->tokenService->listarTokensDoUsuario($usuarioUuid, $apenasValidos);
        
        return Response::json([
            'tokens' => $tokens,
            'total' => count($tokens),
        ]);
    }
    
    /**
     * Revoga todos os tokens do usuário autenticado
     * 
     * POST /api/auth/tokens/revoke-all
     * Requer autenticação
     */
    public function revogarTodos(Request $request): Response
    {
        $usuarioUuid = $request->usuarioUuid ?? null;
        
        if (!$usuarioUuid) {
            return Response::json([
                'error' => 'Usuário não autenticado',
            ], 401);
        }
        
        $data = $request->body ?? [];
        $motivo = $data['motivo'] ?? 'Revogação em massa pelo usuário';
        
        $count = $this->tokenService->revogarTodosTokensDoUsuario($usuarioUuid, $motivo);
        
        return Response::json([
            'message' => 'Tokens revogados com sucesso',
            'tokens_revogados' => $count,
        ]);
    }
    
    /**
     * Limpa tokens expirados (admin only)
     * 
     * POST /api/auth/tokens/cleanup
     * Requer autenticação de admin
     */
    public function limpar(Request $request): Response
    {
        $expirados = $this->tokenService->limparTokensExpirados();
        $revogados = $this->tokenService->limparTokensRevogadosAntigos(30);
        
        return Response::json([
            'message' => 'Limpeza concluída',
            'tokens_expirados_removidos' => $expirados,
            'tokens_revogados_removidos' => $revogados,
            'total_removidos' => $expirados + $revogados,
        ]);
    }
}
