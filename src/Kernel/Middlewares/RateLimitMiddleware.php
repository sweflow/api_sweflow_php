<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\RateLimitStorageInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\Storage\RateLimitStorageFactory;
use Src\Kernel\Support\TokenExtractor;

/**
 * Rate Limiting por IP + usuário autenticado.
 * Suporta Redis (distribuído) e File (servidor único) via RateLimitStorageInterface.
 *
 * Uso nas rotas:
 *   [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60, 'key' => 'auth.login']]
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private int    $limit;
    private int    $window;
    private string $keyPrefix;
    private int    $userLimit;
    private RateLimitStorageInterface $storage;

    public function __construct(
        int    $limit      = 60,
        int    $window     = 60,
        string $key        = '',
        int    $user_limit = 0,
        ?RateLimitStorageInterface $storage = null
    ) {
        $this->limit     = $limit;
        $this->window    = $window;
        $this->keyPrefix = $key;
        $this->userLimit = $user_limit > 0 ? $user_limit : $limit;
        $this->storage   = $storage ?? RateLimitStorageFactory::create();
    }

    public function handle(Request $request, callable $next): Response
    {
        $ip = IpResolver::resolve();

        // ── Rate limit por IP ────────────────────────────
        $ipKey             = $this->buildKey($request, 'ip:' . $ip);
        [$ipCount, $resetAt] = $this->storage->increment($ipKey, $this->window);

        if ($ipCount > $this->limit) {
            $retryAfter = max(0, $resetAt - time());
            $this->logAbuse('rate_limit.ip', $ip, $request->getUri(), $ipCount);
            // Acumula score no ThreatScorer (singleton via container se disponível)
            $this->addThreatScore($ip, \Src\Kernel\Support\ThreatScorer::SCORE_RATE_LIMIT);
            return $this->tooManyResponse((int) $retryAfter, $resetAt);
        }

        // ── Rate limit por usuário autenticado ───────────
        $userIdentifier = $this->resolveUserIdentifier($request);
        if ($userIdentifier !== null) {
            $userKey               = $this->buildKey($request, 'user:' . $userIdentifier);
            [$userCount, $userReset] = $this->storage->increment($userKey, $this->window);

            if ($userCount > $this->userLimit) {
                $retryAfter = max(0, $userReset - time());
                $this->logAbuse('rate_limit.user', $ip, $request->getUri(), $userCount, $userIdentifier);
                return $this->tooManyResponse((int) $retryAfter, $userReset);
            }
        }

        // Purge ocasional (1% das req) — no-op em Redis
        if (random_int(1, 100) === 1) {
            $this->storage->purgeExpired();
        }

        $remaining = max(0, $this->limit - $ipCount);
        $response  = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset'     => (string) $resetAt,
        ]);
    }

    private function tooManyResponse(int $retryAfter, int $resetAt): Response
    {
        return Response::json(
            ['error' => 'Muitas requisições. Tente novamente em ' . $retryAfter . ' segundos.'],
            429
        )->withHeaders([
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset'     => (string) $resetAt,
            'Retry-After'           => (string) $retryAfter,
        ]);
    }

    private function resolveUserIdentifier(Request $request): ?string
    {
        $authUser = $request->attribute('auth_user');
        if ($authUser !== null && is_object($authUser) && method_exists($authUser, 'getUuid')) {
            return (string) $authUser->getUuid();
        }
        $token = TokenExtractor::fromRequest();
        if ($token !== '') {
            $sub = $this->extractSubFromToken($token);
            if ($sub !== null) {
                return $sub;
            }
        }
        return null;
    }

    private function buildKey(Request $request, string $suffix): string
    {
        $prefix = $this->keyPrefix !== '' ? $this->keyPrefix : $request->getUri();
        return 'rl:' . $prefix . ':' . $suffix;
    }

    private function addThreatScore(string $ip, int $score): void
    {
        try {
            // Reutiliza o mesmo storage já instanciado — evita I/O redundante
            (new \Src\Kernel\Support\ThreatScorer($this->storage))->add($ip, $score);
        } catch (\Throwable) {
            // Falha silenciosa — rate limit não deve quebrar por isso
        }
    }

    private function logAbuse(string $type, string $ip, string $uri, int $count, ?string $userId = null): void
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($env === 'testing') {
            return;
        }
        $line = json_encode([
            'timestamp'       => date('Y-m-d\TH:i:sP'),
            'type'            => 'RATE_LIMIT_EXCEEDED',
            'subtype'         => $type,
            'ip'              => $ip,
            'uri'             => $uri,
            'count'           => $count,
            'user_identifier' => $userId,
            'user_agent'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }

    private function extractSubFromToken(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $sub = $payload['sub'] ?? null;
        return is_string($sub) && $sub !== '' ? $sub : null;
    }
}
