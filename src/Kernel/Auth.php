<?php

namespace Src\Kernel;

use Src\Kernel\Contracts\AuthenticatableInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\ApiTokenMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\CircuitBreakerMiddleware;
use Src\Kernel\Middlewares\OptionalAuthHybridMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;

/**
 * Fachada de autenticação — simplifica o uso do Auth nas rotas dos módulos.
 *
 * Uso nas rotas (Routes/web.php):
 *
 *   use Src\Kernel\Auth;
 *
 *   // Rota pública
 *   $router->get('/api/produtos', [Controller::class, 'listar']);
 *
 *   // Rota privada (qualquer usuário logado)
 *   $router->get('/api/perfil', [Controller::class, 'perfil'], Auth::user());
 *
 *   // Rota admin
 *   $router->post('/api/admin/produtos', [Controller::class, 'criar'], Auth::admin());
 *
 *   // Rota com rate limit
 *   $router->post('/api/produtos', [Controller::class, 'criar'], Auth::user(limit: 10));
 *
 *   // Rota com proteção de banco
 *   $router->post('/api/produtos', [Controller::class, 'criar'], Auth::admin(db: true));
 *
 * Uso nos Controllers:
 *
 *   $user = Auth::current($request);   // retorna AuthenticatableInterface ou null
 *   $id   = Auth::id($request);        // retorna o UUID do usuário logado ou null
 *   $role = Auth::role($request);      // retorna o nível de acesso ou null
 *   Auth::check($request);             // lança 401 se não autenticado
 *   Auth::checkAdmin($request);        // lança 403 se não for admin_system
 */
final class Auth
{
    // ── Middlewares para rotas ────────────────────────────────────────────

    /**
     * Rota privada — exige qualquer usuário autenticado.
     *
     * @param int    $limit  Máximo de requisições por minuto (0 = sem limite)
     * @param string $key    Chave do rate limit (padrão: gerada automaticamente)
     * @param bool   $db     Adiciona circuit breaker para proteção do banco
     */
    public static function user(int $limit = 0, string $key = '', bool $db = false): array
    {
        return self::build([AuthHybridMiddleware::class], $limit, $key, $db);
    }

    /**
     * Rota admin — exige admin_system com JWT_API_SECRET.
     *
     * @param int    $limit  Máximo de requisições por minuto (0 = sem limite)
     * @param string $key    Chave do rate limit
     * @param bool   $db     Adiciona circuit breaker
     */
    public static function admin(int $limit = 0, string $key = '', bool $db = false): array
    {
        return self::build([AuthHybridMiddleware::class, AdminOnlyMiddleware::class], $limit, $key, $db);
    }

    /**
     * Rota de API — exige token de API (JWT_API_SECRET, tipo: 'api').
     * Usado para integrações machine-to-machine.
     */
    public static function api(int $limit = 0, string $key = ''): array
    {
        return self::build([ApiTokenMiddleware::class], $limit, $key, false);
    }

    /**
     * Rota com autenticação opcional — injeta o usuário se logado, mas não bloqueia.
     * Útil para conteúdo diferente para logados/não logados.
     */
    public static function optional(): array
    {
        return [OptionalAuthHybridMiddleware::class];
    }

    /**
     * Apenas rate limit — sem autenticação.
     * Útil para rotas públicas que precisam de proteção contra abuso.
     */
    public static function limit(int $limit, int $window = 60, string $key = ''): array
    {
        return [[RateLimitMiddleware::class, [
            'limit'  => $limit,
            'window' => $window,
            'key'    => $key,
        ]]];
    }

    /**
     * Apenas circuit breaker — sem autenticação.
     */
    public static function db(int $threshold = 5, int $cooldown = 20): array
    {
        return [[CircuitBreakerMiddleware::class, [
            'service'   => 'database',
            'threshold' => $threshold,
            'cooldown'  => $cooldown,
        ]]];
    }

    // ── Helpers para Controllers ──────────────────────────────────────────

    /**
     * Retorna o usuário autenticado ou null.
     */
    public static function current(Request $request): ?AuthenticatableInterface
    {
        $user = $request->attribute('auth_user');
        return ($user instanceof AuthenticatableInterface) ? $user : null;
    }

    /**
     * Retorna o ID (UUID) do usuário autenticado ou null.
     */
    public static function id(Request $request): ?string
    {
        return self::current($request)?->getAuthId();
    }

    /**
     * Retorna o nível de acesso do usuário autenticado ou null.
     */
    public static function role(Request $request): ?string
    {
        return self::current($request)?->getAuthRole();
    }

    /**
     * Verifica se o usuário está autenticado.
     * Lança DomainException(401) se não estiver.
     */
    public static function check(Request $request): AuthenticatableInterface
    {
        $user = self::current($request);
        if ($user === null) {
            throw new \DomainException('Não autenticado.', 401);
        }
        return $user;
    }

    /**
     * Verifica se o usuário é admin_system.
     * Lança DomainException(403) se não for.
     */
    public static function checkAdmin(Request $request): AuthenticatableInterface
    {
        $user = self::check($request);
        if ($user->getAuthRole() !== 'admin_system') {
            throw new \DomainException('Acesso restrito.', 403);
        }
        return $user;
    }

    /**
     * Verifica se o usuário tem um dos papéis informados.
     * Lança DomainException(403) se não tiver.
     */
    public static function checkRole(Request $request, string ...$roles): AuthenticatableInterface
    {
        $user = self::check($request);
        if (!in_array($user->getAuthRole(), $roles, true)) {
            throw new \DomainException('Acesso restrito.', 403);
        }
        return $user;
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private static function build(array $middlewares, int $limit, string $key, bool $db): array
    {
        $stack = [];

        if ($limit > 0) {
            $stack[] = [RateLimitMiddleware::class, [
                'limit'  => $limit,
                'window' => 60,
                'key'    => $key ?: 'auth.' . md5(implode(',', $middlewares)),
            ]];
        }

        if ($db) {
            $stack[] = [CircuitBreakerMiddleware::class, [
                'service'   => 'database',
                'threshold' => 5,
                'cooldown'  => 20,
            ]];
        }

        foreach ($middlewares as $mw) {
            $stack[] = $mw;
        }

        return $stack;
    }
}
