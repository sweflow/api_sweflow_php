<?php

namespace Src\Modules\Authenticador\Middlewares;

use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;

/**
 * Middleware: AdminOnlyMiddleware
 * 
 * Permite acesso apenas para administradores (admin ou super_admin)
 * Deve ser usado APÓS o AuthMiddleware
 */
class AdminOnlyMiddleware
{
    /**
     * Executa o middleware
     */
    public function handle(Request $request, callable $next): Response
    {
        // Verifica se o usuário está autenticado
        if (!isset($request->usuario)) {
            return Response::json([
                'error' => 'Não autenticado',
                'message' => 'É necessário estar autenticado para acessar este recurso',
            ], 401);
        }
        
        // Verifica se é admin ou super_admin
        $nivelAcesso = $request->usuario->getNivelAcesso();
        
        if (!in_array($nivelAcesso, ['admin', 'super_admin'])) {
            return Response::json([
                'error' => 'Acesso negado',
                'message' => 'Este recurso é restrito a administradores',
                'required_level' => 'admin ou super_admin',
                'your_level' => $nivelAcesso,
            ], 403);
        }
        
        // Continua para o próximo middleware/controller
        return $next($request);
    }
}
