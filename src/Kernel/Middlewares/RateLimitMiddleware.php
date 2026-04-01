<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Rate Limiting por IP usando storage em arquivo (sem dependência de Redis).
 *
 * Uso nas rotas:
 *   [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60]]
 *
 * Parâmetros:
 *   limit  — máximo de requisições na janela (padrão: 60)
 *   window — janela em segundos (padrão: 60)
 *   key    — prefixo de chave customizado (padrão: usa URI da rota)
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private int $limit;
    private int $window;
    private string $keyPrefix;
    private string $storageDir;

    public function __construct(int $limit = 60, int $window = 60, string $key = '')
    {
        $this->limit      = $limit;
        $this->window     = $window;
        $this->keyPrefix  = $key;
        $this->storageDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
    }

    public function handle(Request $request, callable $next): Response
    {
        $ip  = $this->resolveIp();
        $key = $this->buildKey($request, $ip);

        [$count, $resetAt] = $this->increment($key);

        $remaining = max(0, $this->limit - $count);
        $retryAfter = max(0, $resetAt - time());

        if ($count > $this->limit) {
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

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset'     => (string) $resetAt,
        ]);
    }

    private function increment(string $key): array
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }

        $file = $this->storageDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $now  = time();

        // Locking para evitar race condition
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            // Se não conseguir criar arquivo, permite a requisição (fail open)
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

        // Janela expirou — reinicia
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

    private function buildKey(Request $request, string $ip): string
    {
        $prefix = $this->keyPrefix !== '' ? $this->keyPrefix : $request->getUri();
        return 'rl:' . $prefix . ':' . $ip;
    }

    private function resolveIp(): string
    {
        // Só confia em headers de proxy se TRUST_PROXY estiver habilitado no .env
        $trustProxy = strtolower(trim($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?: 'false'));
        if (in_array($trustProxy, ['1', 'true', 'yes'], true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                $val = $_SERVER[$header] ?? '';
                if ($val !== '') {
                    // X-Forwarded-For pode ter múltiplos IPs — pega o primeiro (cliente original)
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
