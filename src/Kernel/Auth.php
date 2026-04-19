<?php

declare(strict_types=1);

namespace Src\Kernel;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\ApiTokenMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\CircuitBreakerMiddleware;
use Src\Kernel\Middlewares\OptionalAuthHybridMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;

/**
 * Fachada de autenticação — simplifica o uso do Auth nas rotas e controllers.
 *
 * Uso nas rotas (Routes/web.php):
 *
 *   $router->get('/api/perfil',  [Controller::class, 'perfil'],  Auth::user());
 *   $router->post('/api/admin',  [Controller::class, 'criar'],   Auth::admin());
 *   $router->post('/api/webhook',[Controller::class, 'receber'], Auth::api());
 *   $router->get('/api/feed',    [Controller::class, 'feed'],    Auth::optional());
 *   $router->post('/api/contato',[Controller::class, 'enviar'],  Auth::limit(5));
 *
 * Uso nos Controllers:
 *
 *   $identity = Auth::identity($request);  // AuthIdentityInterface|null
 *   $user     = Auth::current($request);   // mixed — o objeto de usuário
 *   $id       = Auth::id($request);        // string|null — UUID ou ID
 *   $role     = Auth::role($request);      // string|null — nível de acesso
 *   Auth::check($request);                 // lança 401 se não autenticado
 *   Auth::checkAdmin($request);            // lança 403 se não for admin
 */
final class Auth
{
    // ── Middlewares para rotas ────────────────────────────────────────────

    /** Rota privada — exige qualquer usuário autenticado. */
    public static function user(int $limit = 0, string $key = '', bool $db = false): array
    {
        return self::build([AuthHybridMiddleware::class], $limit, $key, $db);
    }

    /** Rota admin — exige admin_system com JWT_API_SECRET. */
    public static function admin(int $limit = 0, string $key = '', bool $db = false): array
    {
        return self::build([AuthHybridMiddleware::class, AdminOnlyMiddleware::class], $limit, $key, $db);
    }

    /** Rota de API — exige token de API (machine-to-machine). */
    public static function api(int $limit = 0, string $key = ''): array
    {
        return self::build([ApiTokenMiddleware::class], $limit, $key, false);
    }

    /** Rota com autenticação opcional — injeta identidade se autenticado, não bloqueia. */
    public static function optional(): array
    {
        return [OptionalAuthHybridMiddleware::class];
    }

    /** Apenas rate limit — sem autenticação. */
    public static function limit(int $limit, int $window = 60, string $key = ''): array
    {
        return [[RateLimitMiddleware::class, ['limit' => $limit, 'window' => $window, 'key' => $key]]];
    }

    /** Apenas circuit breaker — sem autenticação. */
    public static function db(int $threshold = 5, int $cooldown = 20): array
    {
        return [[CircuitBreakerMiddleware::class, [
            'service' => 'database', 'threshold' => $threshold, 'cooldown' => $cooldown,
        ]]];
    }

    // ── Helpers para Controllers ──────────────────────────────────────────

    /**
     * Retorna a identidade tipada do Request.
     * Fonte única de verdade — sem strings mágicas.
     */
    public static function identity(Request $request): ?AuthIdentityInterface
    {
        $identity = $request->attribute(AuthContextInterface::IDENTITY_KEY);
        return $identity instanceof AuthIdentityInterface ? $identity : null;
    }

    /**
     * Retorna o objeto de usuário autenticado, ou null.
     */
    public static function current(Request $request): mixed
    {
        return self::identity($request)?->user();
    }

    /**
     * Retorna o ID do usuário autenticado, ou null.
     */
    public static function id(Request $request): string|int|null
    {
        return self::identity($request)?->id();
    }

    /**
     * Retorna o role/nível de acesso do usuário, ou null.
     */
    public static function role(Request $request): ?string
    {
        return self::identity($request)?->role();
    }

    /**
     * Retorna o tipo da identidade: 'user', 'api_token', 'guest', 'inactive', etc.
     */
    public static function type(Request $request): string
    {
        return self::identity($request)?->type() ?? 'guest';
    }

    /**
     * Garante que o usuário está autenticado.
     * @throws \DomainException 401 se não autenticado
     */
    public static function check(Request $request): AuthIdentityInterface
    {
        $identity = self::identity($request);
        if ($identity === null || !$identity->isAuthenticated()) {
            throw new \DomainException('Não autenticado.', 401);
        }
        return $identity;
    }

    /**
     * Garante que o usuário é admin.
     * @throws \DomainException 401 se não autenticado, 403 se não for admin
     */
    public static function checkAdmin(Request $request): AuthIdentityInterface
    {
        $identity = self::check($request);

        if (!$identity->hasRole('admin_system')) {
            throw new \DomainException('Acesso restrito.', 403);
        }

        return $identity;
    }

    /**
     * Garante que o usuário tem um dos papéis informados.
     * @throws \DomainException 401 se não autenticado, 403 se não tiver o papel
     */
    public static function checkRole(Request $request, string ...$roles): AuthIdentityInterface
    {
        $identity = self::check($request);

        if (!$identity->hasRole(...$roles)) {
            throw new \DomainException('Acesso restrito.', 403);
        }

        return $identity;
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
                'service' => 'database', 'threshold' => 5, 'cooldown' => 20,
            ]];
        }

        foreach ($middlewares as $mw) {
            $stack[] = $mw;
        }

        return $stack;
    }
}
