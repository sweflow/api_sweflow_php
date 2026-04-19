<?php

namespace Src\Modules\Authenticador\Middlewares;

use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;

/**
 * Middleware: OwnerOrAdminMiddleware
 * 
 * Permite acesso apenas para o dono do recurso ou administradores
 * Útil para endpoints como "editar próprio perfil"
 * Deve ser usado APÓS o AuthMiddleware
 */
class OwnerOrAdminMiddleware
{
    private string $paramName;
    
    /**
     * @param string $paramName Nome do parâmetro que contém o UUID do recurso (ex: 'uuid', 'usuario_uuid')
     */
    public function __construct(string $paramName = 'uuid')
    {
        $this->paramName = $paramName;
    }
    
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
        
        // Admins têm acesso a tudo
        $nivelAcesso = $request->usuario->getNivelAcesso();
        if (in_array($nivelAcesso, ['admin', 'super_admin'])) {
            return $next($request);
        }
        
        // Verifica se é o dono do recurso
        $resourceUuid = $request->params[$this->paramName] ?? null;
        
        if (!$resourceUuid) {
            return Response::json([
                'error' => 'Parâmetro inválido',
                'message' => 'UUID do recurso não fornecido',
            ], 400);
        }
        
        if ($resourceUuid !== $request->usuarioUuid) {
            return Response::json([
                'error' => 'Acesso negado',
                'message' => 'Você só pode acessar seus próprios recursos',
            ], 403);
        }
        
        // Continua para o próximo middleware/controller
        return $next($request);
    }
    
    /**
     * Factory method para criar o middleware
     */
    public static function forParam(string $paramName = 'uuid'): callable
    {
        return function () use ($paramName) {
            return new self($paramName);
        };
    }
}
