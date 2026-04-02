<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Rate Limiting por IP + usuário autenticado (sem dependência de Redis).
 *
 * Uso nas rotas:
 *   [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60, 'key' => 'auth.login']]
 *
 * Parâmetros:
 *   limit      — máximo de requisições na janela (padrão: 60)
 *   window     — janela em segundos (padrão: 60)
 *   key        — prefixo de chave customizado (padrão: usa URI da rota)
 *   user_limit — limite adicional por usuário autenticado (padrão: igual ao limit)
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private int    $limit;
    private int    $window;
    private string $keyPrefix;
    private int    $userLimit;
    private string $storageDir;

    public function __construct(
        int    $limit      = 60,
        int    $window     = 60,
        string $key        = '',
        int    $user_limit = 0
    ) {
        $this->limit      = $limit;
        $this->window     = $window;
        $this->keyPrefix  = $key;
        $this->userLimit  = $user_limit > 0 ? $user_limit : $limit;
        $this->storageDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
    }

    public function handle(Request $request, callable $next): Response
    {
        $ip = $this->resolveIp();

        // ── Rate limit por IP ────────────────────────────
        $ipKey              = $this->buildKey($request, 'ip:' . $ip);
        [$ipCount, $resetAt] = $this->increment($ipKey);

        if ($ipCount > $this->limit) {
            $retryAfter = max(0, $resetAt - time());
            $this->logAbuse('rate_limit.ip', $ip, $request->getUri(), $ipCount);
            return $this->tooManyResponse($retryAfter, $resetAt);
        }

        // ── Rate limit por usuário autenticado ───────────
        // Protege contra atacantes que rotacionam IPs/VPNs
        $userIdentifier = $this->resolveUserIdentifier($request);
        if ($userIdentifier !== null) {
            $userKey              = $this->buildKey($request, 'user:' . $userIdentifier);
            [$userCount, $userReset] = $this->increment($userKey);

            if ($userCount > $this->userLimit) {
                $retryAfter = max(0, $userReset - time());
                $this->logAbuse('rate_limit.user', $ip, $request->getUri(), $userCount, $userIdentifier);
                return $this->tooManyResponse($retryAfter, $userReset);
            }
        }

        // Limpeza ocasional de arquivos expirados (1% das requisições)
        if (random_int(1, 100) === 1) {
            $this->purgeExpired();
        }

        $remaining = max(0, $this->limit - $ipCount);
        $response  = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset'     => (string) $resetAt,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────

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

    /**
     * Resolve o identificador do usuário autenticado a partir do request.
     * Tenta: atributo auth_user (injetado por AuthHybridMiddleware) → sub do JWT → null.
     */
    private function resolveUserIdentifier(Request $request): ?string
    {
        // Usuário já autenticado pelo middleware anterior
        $authUser = $request->attribute('auth_user');
        if ($authUser !== null && method_exists($authUser, 'getUuid')) {
            return (string) $authUser->getUuid();
        }

        // Tenta extrair sub do JWT sem validar assinatura (só para rate limit — não é auth)
        $token = $this->extractRawToken();
        if ($token !== null) {
            $sub = $this->extractSubFromToken($token);
            if ($sub !== null) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * Extrai o sub do JWT sem verificar assinatura.
     * Usado apenas para identificar o usuário no rate limit — não é autenticação.
     */
    private function extractSubFromToken(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true,
                4
            );
            $sub = $payload['sub'] ?? null;
            // Valida que sub parece um UUID ou identificador seguro
            if (is_string($sub) && strlen($sub) >= 8 && strlen($sub) <= 64) {
                return $sub;
            }
        } catch (\Throwable) {
            // ignora
        }
        return null;
    }

    private function extractRawToken(): ?string
    {
        $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($bearer, 'Bearer ')) {
            $t = trim(substr($bearer, 7));
            return $t !== '' ? $t : null;
        }
        $cookie = $_COOKIE['auth_token'] ?? '';
        if (is_string($cookie) && trim($cookie) !== '') {
            return trim($cookie);
        }
        return null;
    }

    private function increment(string $key): array
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }

        $file = $this->storageDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $now  = time();

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return [0, $now + $this->window];
        }

        flock($fp, LOCK_EX);

        $data = [];
        $raw  = stream_get_contents($fp);
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true) ?? [];
        }

        $resetAt = (int) ($data['reset_at'] ?? 0);
        $count   = (int) ($data['count']    ?? 0);

        if ($now >= $resetAt) {
            $resetAt = $now + $this->window;
            $count   = 0;
        }

        $count++;
        $data = ['count' => $count, 'reset_at' => $resetAt];

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);

        return [$count, $resetAt];
    }

    private function buildKey(Request $request, string $suffix): string
    {
        $prefix = $this->keyPrefix !== '' ? $this->keyPrefix : $request->getUri();
        return 'rl:' . $prefix . ':' . $suffix;
    }

    /**
     * Loga abuso em stderr no formato estruturado para Fail2Ban e observabilidade.
     * Formato: JSON com campo "type": "RATE_LIMIT_EXCEEDED" — Fail2Ban filtra por isso.
     */
    private function logAbuse(
        string  $type,
        string  $ip,
        string  $uri,
        int     $count,
        ?string $userIdentifier = null
    ): void {
        $line = json_encode([
            'timestamp'       => date('Y-m-d\TH:i:sP'),
            'type'            => 'RATE_LIMIT_EXCEEDED',
            'subtype'         => $type,
            'ip'              => $ip,
            'uri'             => $uri,
            'count'           => $count,
            'user_identifier' => $userIdentifier,
            'user_agent'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }

    private function purgeExpired(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }
        $now = time();
        foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data    = json_decode($raw, true) ?? [];
            $resetAt = (int) ($data['reset_at'] ?? 0);
            if ($resetAt > 0 && $now > $resetAt + 300) {
                @unlink($file);
            }
        }
    }

    private function resolveIp(): string
    {
        $trustProxy = strtolower(trim($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?: 'false'));
        if (in_array($trustProxy, ['1', 'true', 'yes'], true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                $val = $_SERVER[$header] ?? '';
                if ($val !== '') {
                    $ip = trim(explode(',', $val)[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
