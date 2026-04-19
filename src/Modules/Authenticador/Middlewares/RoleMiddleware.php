<?php

namespace Src\Modules\Authenticador\Middlewares;

use PDO;
use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;
use Src\Modules\Usuarios2\Services\PermissaoService;

/**
 * Middleware: RoleMiddleware
 * 
 * Verifica se o usuário tem uma role específica
 * Deve ser usado APÓS o AuthMiddleware
 */
class RoleMiddleware
{
    private PDO $pdo;
    private PermissaoService $permissaoService;
    private array $rolesSlugs;
    private bool $requireAll;
    
    /**
     * @param PDO $pdo
     * @param string|array $rolesSlugs Slug da role ou array de slugs
     * @param bool $requireAll Se true, requer todas as roles. Se false, requer pelo menos uma
     */
    public function __construct(PDO $pdo, $rolesSlugs, bool $requireAll = false)
    {
        $this->pdo = $pdo;
        $this->permissaoService = new PermissaoService($pdo);
        $this->rolesSlugs = is_array($rolesSlugs) ? $rolesSlugs : [$rolesSlugs];
        $this->requireAll = $requireAll;
    }
    
    /**
     * Executa o middleware
     */
    public function handle(Request $request, callable $next): Response
    {
        // Verifica se o usuário está autenticado
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
        
        // Verifica as roles
        $rolesEncontradas = [];
        foreach ($this->rolesSlugs as $roleSlug) {
            if ($this->permissaoService->usuarioTemRole($request->usuarioUuid, $roleSlug)) {
                $rolesEncontradas[] = $roleSlug;
            }
        }
        
        // Verifica se atende aos requisitos
        $temAcesso = false;
        
        if ($this->requireAll) {
            // Requer todas as roles
            $temAcesso = count($rolesEncontradas) === count($this->rolesSlugs);
        } else {
            // Requer pelo menos uma role
            $temAcesso = count($rolesEncontradas) > 0;
        }
        
        if (!$temAcesso) {
            return Response::json([
                'error' => 'Acesso negado',
                'message' => 'Você não tem a role necessária para acessar este recurso',
                'required_roles' => $this->rolesSlugs,
                'require_all' => $this->requireAll,
            ], 403);
        }
        
        // Continua para o próximo middleware/controller
        return $next($request);
    }
    
    /**
     * Factory method para criar o middleware com uma role
     */
    public static function require($rolesSlugs, bool $requireAll = false): callable
    {
        return function (PDO $pdo) use ($rolesSlugs, $requireAll) {
            return new self($pdo, $rolesSlugs, $requireAll);
        };
    }
}
