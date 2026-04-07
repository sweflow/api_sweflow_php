<?php

namespace Src\Kernel\Support\Storage;

use Src\Kernel\Contracts\RateLimitStorageInterface;

/**
 * Storage de rate limit baseado em Redis.
 * Suporta ambiente distribuído (múltiplos containers/servidores).
 *
 * Requer ext-redis ou Predis. Usa operações atômicas INCR + EXPIRE.
 *
 * Configuração via .env:
 *   REDIS_HOST=127.0.0.1
 *   REDIS_PORT=6379
 *   REDIS_PASSWORD=
 *   REDIS_DB=0
 *   REDIS_PREFIX=sweflow:
 */
class RedisRateLimitStorage implements RateLimitStorageInterface
{
    private \Redis $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Cria instância a partir das variáveis de ambiente.
     * Retorna null se Redis não estiver disponível.
     */
    public static function fromEnv(): ?self
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        $host     = $_ENV['REDIS_HOST']     ?? getenv('REDIS_HOST')     ?: '127.0.0.1';
        $port     = (int) ($_ENV['REDIS_PORT']     ?? getenv('REDIS_PORT')     ?: 6379);
        $password = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
        $db       = (int) ($_ENV['REDIS_DB']       ?? getenv('REDIS_DB')       ?: 0);
        $prefix   = $_ENV['REDIS_PREFIX']   ?? getenv('REDIS_PREFIX')   ?: 'sweflow:';

        try {
            $redis = new \Redis();
            $redis->connect($host, $port, 2.0); // timeout 2s
            if ($password !== '') {
                $redis->auth($password);
            }
            $redis->select($db);
            $redis->setOption(\Redis::OPT_PREFIX, $prefix);
            return new self($redis);
        } catch (\Throwable) {
            return null;
        }
    }

    public function increment(string $key, int $windowSeconds): array
    {
        $rlKey   = 'rl:' . $key;
        $resetKey = 'rl_reset:' . $key;

        // Pipeline atômico: INCR + EXPIRE
        $pipe = $this->redis->pipeline();
        $pipe->incr($rlKey);
        $pipe->expire($rlKey, $windowSeconds);
        $pipe->get($resetKey);
        $results = $pipe->exec();
        $results = is_array($results) ? $results : [];

        $count = (int) ($results[0] ?? 1);

        // Armazena o timestamp de reset na primeira requisição da janela
        if ($count === 1) {
            $resetAt = time() + $windowSeconds;
            $this->redis->setex($resetKey, $windowSeconds + 5, (string) $resetAt);
        } else {
            $resetAt = (int) ($results[2] ?: time() + $windowSeconds);
        }

        return [$count, $resetAt];
    }

    public function get(string $key): int
    {
        $val = $this->redis->get('score:' . $key);
        return $val !== false ? (int) $val : 0;
    }

    public function addWithTtl(string $key, int $delta, int $ttlSeconds): int
    {
        $rKey = 'score:' . $key;

        $pipe = $this->redis->pipeline();
        $pipe->incrBy($rKey, $delta);
        $pipe->expire($rKey, $ttlSeconds);
        $results = $pipe->exec();
        $results = is_array($results) ? $results : [];

        return (int) ($results[0] ?? $delta);
    }

    public function delete(string $key): void
    {
        $this->redis->del('rl:' . $key, 'rl_reset:' . $key, 'score:' . $key);
    }

    public function purgeExpired(): void
    {
        // Redis gerencia TTL automaticamente — no-op
    }

    /**
     * SET NX atômico: define a chave com TTL apenas se ela não existir.
     * Usa SET key value NX EX ttl — operação atômica nativa do Redis.
     */
    public function setNx(string $key, int $ttlSeconds): bool
    {
        $result = $this->redis->set('lock:' . $key, '1', ['NX', 'EX' => $ttlSeconds]);
        return $result !== false;
    }

    public function deleteLock(string $key): void
    {
        $this->redis->del('lock:' . $key);
    }
}
