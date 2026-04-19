<?php

namespace Src\Modules\Authenticador\Middlewares;

use PDO;
use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;
use Src\Modules\Usuarios2\Services\SessaoService;
use Src\Modules\Usuarios2\Services\Usuario2Service;

/**
 * Middleware: AuthMiddleware
 * 
 * Verifica se o usuário está autenticado através do token JWT
 * Adiciona informações do usuário e sessão no Request
 */
class AuthMiddleware
{
    private PDO $pdo;
    private SessaoService $sessaoService;
    private Usuario2Service $usuarioService;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->sessaoService = new SessaoService($pdo);
        $this->usuarioService = new Usuario2Service($pdo);
    }
    
    /**
     * Executa o middleware
     */
    public function handle(Request $request, callable $next): Response
    {
        // Extrai o token do header Authorization
        $token = $this->extrairToken($request);
        
        if (!$token) {
            return Response::json([
                'error' => 'Token não fornecido',
                'message' => 'É necessário fornecer um token de autenticação',
            ], 401);
        }
        
        // Valida o token e busca a sessão
        $sessao = $this->sessaoService->validarToken($token);
        
        if (!$sessao) {
            return Response::json([
                'error' => 'Token inválido ou expirado',
                'message' => 'O token fornecido é inválido ou expirou',
            ], 401);
        }
        
        // Busca o usuário
        try {
            $usuario = $this->usuarioService->buscarPorUuid($sessao->getUsuarioUuid());
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'Usuário não encontrado',
                'message' => 'O usuário associado ao token não foi encontrado',
            ], 401);
        }
        
        // Verifica se o usuário pode acessar
        $podeLogar = $usuario->podeLogar();
        if (!$podeLogar['pode']) {
            return Response::json([
                'error' => 'Acesso negado',
                'message' => $podeLogar['motivo'],
            ], 403);
        }
        
        // Adiciona informações no request para uso posterior
        $request->usuario = $usuario;
        $request->usuarioUuid = $usuario->getUuid();
        $request->sessao = $sessao;
        $request->sessaoUuid = $sessao->getUuid();
        $request->pdo = $this->pdo;
        
        // Continua para o próximo middleware/controller
        return $next($request);
    }
    
    /**
     * Extrai o token do header Authorization
     * 
     * Suporta os formatos:
     * - Authorization: Bearer <token>
     * - Authorization: <token>
     */
    private function extrairToken(Request $request): ?string
    {
        // Tenta pegar do header Authorization
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        
        if (!$authHeader) {
            // Fallback para Apache
            $authHeader = apache_request_headers()['Authorization'] ?? null;
        }
        
        if (!$authHeader) {
            return null;
        }
        
        // Remove "Bearer " se presente
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        // Retorna o token direto
        return $authHeader;
    }
}
