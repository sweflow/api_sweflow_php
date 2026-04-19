<?php

namespace Src\Modules\Authenticador\Controllers;

use PDO;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Authenticador\Services\AuthenticationService;
use Src\Modules\Authenticador\Exceptions\ValidacaoException;

/**
 * Controller: LoginController
 * 
 * Gerencia login e autenticação de usuários
 */
class LoginController
{
    private PDO $pdo;
    private AuthenticationService $authService;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->authService = new AuthenticationService($pdo);
    }
    
    /**
     * Login com email e senha
     * 
     * POST /api/auth/login
     * Body: { "email": "...", "senha": "..." }
     */
    public function login(Request $request): Response
    {
        try {
            $data = $request->body ?? [];
            
            if (empty($data['email']) || empty($data['senha'])) {
                return Response::json([
                    'error' => 'Email e senha são obrigatórios',
                ], 400);
            }
            
            $resultado = $this->authService->login(
                $data['email'],
                $data['senha'],
                false // não é OAuth
            );
            
            return Response::json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'token' => $resultado['access_token'],
                'refresh_token' => $resultado['refresh_token'],
                'expires_in' => $resultado['expires_in'],
                'token_type' => $resultado['token_type'],
                'usuario' => $resultado['usuario'],
            ], 200);
            
        } catch (ValidacaoException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Exception $e) {
            error_log('[LoginController] Erro no login: ' . $e->getMessage());
            return Response::json([
                'error' => $e->getMessage(),
            ], 401);
        }
    }
    
    /**
     * Logout (revoga token atual)
     * 
     * POST /api/auth/logout
     * Header: Authorization: Bearer {token}
     */
    public function logout(Request $request): Response
    {
        try {
            $token = $this->extractToken($request);
            
            if (!$token) {
                return Response::json([
                    'error' => 'Token não fornecido',
                ], 401);
            }
            
            $this->authService->revogarToken($token);
            
            return Response::json([
                'success' => true,
                'message' => 'Logout realizado com sucesso',
            ], 200);
            
        } catch (\Exception $e) {
            error_log('[LoginController] Erro no logout: ' . $e->getMessage());
            return Response::json([
                'error' => 'Erro interno do servidor',
            ], 500);
        }
    }
    
    /**
     * Extrai token do header Authorization
     */
    private function extractToken(Request $request): ?string
    {
        $auth = $request->header('Authorization');
        
        if (!$auth) {
            return null;
        }
        
        if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
}
