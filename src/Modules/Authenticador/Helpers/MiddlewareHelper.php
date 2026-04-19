<?php

namespace Src\Modules\Authenticador\Helpers;

use PDO;
use Src\Modules\Authenticador\Middlewares\AuthMiddleware;
use Src\Modules\Authenticador\Middlewares\PermissionMiddleware;
use Src\Modules\Authenticador\Middlewares\RoleMiddleware;
use Src\Modules\Authenticador\Middlewares\RateLimitMiddleware;
use Src\Modules\Authenticador\Middlewares\CorsMiddleware;
use Src\Modules\Authenticador\Middlewares\AdminOnlyMiddleware;
use Src\Modules\Authenticador\Middlewares\OwnerOrAdminMiddleware;

/**
 * Helper: MiddlewareHelper
 * 
 * Facilita a criação e uso de middlewares
 * Fornece métodos estáticos para criar middlewares de forma simples
 */
class MiddlewareHelper
{
    /**
     * Cria middleware de autenticação
     */
    public static function auth(PDO $pdo): AuthMiddleware
    {
        return new AuthMiddleware($pdo);
    }
    
    /**
     * Cria middleware de permissão
     * 
     * @param PDO $pdo
     * @param string $permissionSlug Slug da permissão (ex: 'usuarios.view')
     */
    public static function permission(PDO $pdo, string $permissionSlug): PermissionMiddleware
    {
        return new PermissionMiddleware($pdo, $permissionSlug);
    }
    
    /**
     * Cria middleware de role
     * 
     * @param PDO $pdo
     * @param string|array $rolesSlugs Slug da role ou array de slugs
     * @param bool $requireAll Se true, requer todas as roles
     */
    public static function role(PDO $pdo, $rolesSlugs, bool $requireAll = false): RoleMiddleware
    {
        return new RoleMiddleware($pdo, $rolesSlugs, $requireAll);
    }
    
    /**
     * Cria middleware de rate limit
     * 
     * @param PDO $pdo
     * @param int $maxRequests Número máximo de requisições
     * @param int $windowSeconds Janela de tempo em segundos
     * @param string $scope Escopo do rate limit
     */
    public static function rateLimit(PDO $pdo, int $maxRequests = 60, int $windowSeconds = 60, string $scope = 'global'): RateLimitMiddleware
    {
        return new RateLimitMiddleware($pdo, $maxRequests, $windowSeconds, $scope);
    }
    
    /**
     * Cria middleware CORS
     */
    public static function cors(array $allowedOrigins = ['*']): CorsMiddleware
    {
        return new CorsMiddleware($allowedOrigins);
    }
    
    /**
     * Cria middleware CORS a partir do .env
     */
    public static function corsFromEnv(): CorsMiddleware
    {
        return CorsMiddleware::fromEnv();
    }
    
    /**
     * Cria middleware de admin only
     */
    public static function adminOnly(): AdminOnlyMiddleware
    {
        return new AdminOnlyMiddleware();
    }
    
    /**
     * Cria middleware de owner or admin
     * 
     * @param string $paramName Nome do parâmetro que contém o UUID
     */
    public static function ownerOrAdmin(string $paramName = 'uuid'): OwnerOrAdminMiddleware
    {
        return new OwnerOrAdminMiddleware($paramName);
    }
    
    /**
     * Cria uma cadeia de middlewares
     * 
     * Exemplo:
     * MiddlewareHelper::chain($pdo, [
     *     'auth',
     *     ['permission', 'usuarios.view'],
     *     ['rateLimit', 100, 60]
     * ])
     */
    public static function chain(PDO $pdo, array $middlewares): array
    {
        $chain = [];
        
        foreach ($middlewares as $middleware) {
            if (is_string($middleware)) {
                // Middleware simples (ex: 'auth', 'adminOnly')
                $chain[] = self::$middleware($pdo);
            } elseif (is_array($middleware)) {
                // Middleware com parâmetros (ex: ['permission', 'usuarios.view'])
                $method = array_shift($middleware);
                $chain[] = self::$method($pdo, ...$middleware);
            }
        }
        
        return $chain;
    }
    
    /**
     * Presets comuns de middlewares
     */
    
    /**
     * Middleware para rotas públicas (apenas CORS e rate limit)
     */
    public static function publicRoute(PDO $pdo): array
    {
        return [
            self::corsFromEnv(),
            self::rateLimit($pdo, 100, 60, 'public'),
        ];
    }
    
    /**
     * Middleware para rotas autenticadas
     */
    public static function authenticatedRoute(PDO $pdo): array
    {
        return [
            self::corsFromEnv(),
            self::rateLimit($pdo, 200, 60, 'authenticated'),
            self::auth($pdo),
        ];
    }
    
    /**
     * Middleware para rotas de admin
     */
    public static function adminRoute(PDO $pdo): array
    {
        return [
            self::corsFromEnv(),
            self::rateLimit($pdo, 500, 60, 'admin'),
            self::auth($pdo),
            self::adminOnly(),
        ];
    }
    
    /**
     * Middleware para rotas de login (rate limit mais restritivo)
     */
    public static function loginRoute(PDO $pdo): array
    {
        return [
            self::corsFromEnv(),
            self::rateLimit($pdo, 5, 60, 'login'), // Apenas 5 tentativas por minuto
        ];
    }
    
    /**
     * Middleware para rotas de API pública (rate limit moderado)
     */
    public static function apiRoute(PDO $pdo): array
    {
        return [
            self::corsFromEnv(),
            self::rateLimit($pdo, 60, 60, 'api'),
        ];
    }
}
