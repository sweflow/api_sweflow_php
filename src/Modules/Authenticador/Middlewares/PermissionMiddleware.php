<?php

namespace Src\Modules\Authenticador\Middlewares;

use PDO;
use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;
use Src\Modules\Usuarios2\Services\PermissaoService;

/**
 * Middleware: PermissionMiddleware
 * 
 * Verifica se o usuário tem uma permissão específica
 * Deve ser usado APÓS o AuthMiddleware
 */
class PermissionMiddleware
{
    private PDO $pdo;
    private PermissaoService $permissaoService;
    private string $permissionSlug;
    
    public function __construct(PDO $pdo, string $permissionSlug)
    {
        $this->pdo = $pdo;
        $this->permissaoService = new PermissaoService($pdo);
        $this->permissionSlug = $permissionSlug;
    }
    
    /**
     * Executa o middleware
     */
    public function handle(Request $request, callable $next): Response
    {
        // Verifica se o usuário está autenticado (deve ter passado pelo AuthMiddleware)
        if (!isset($request->usuarioUuid)) {
            return Response::json([
                'error' => 'Não autenticado',
                'message' => 'É necessário estar autenticado para acessar este recurso',
            ], 401);
        }
        
        // Super admins têm acesso a tudo
        if (isset($request->usuario) && $request->usuario->getNivelAcesso() === 'super_admin') {
            return $next($request);
        }
        
        // Verifica se o usuário tem a permissão
        $temPermissao = $this->permissaoService->usuarioTemPermissao(
            $request->usuarioUuid,
            $this->permissionSlug
        );
        
        if (!$temPermissao) {
            return Response::json([
                'error' => 'Permissão negada',
                'message' => 'Você não tem permissão para acessar este recurso',
                'required_permission' => $this->permissionSlug,
            ], 403);
        }
        
        // Continua para o próximo middleware/controller
        return $next($request);
    }
    
    /**
     * Factory method para criar o middleware com a permissão
     */
    public static function require(string $permissionSlug): callable
    {
        return function (PDO $pdo) use ($permissionSlug) {
            return new self($pdo, $permissionSlug);
        };
    }
}
