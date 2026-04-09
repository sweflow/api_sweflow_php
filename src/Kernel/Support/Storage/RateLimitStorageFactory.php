<?php

namespace Src\Kernel\Support\Storage;

use Src\Kernel\Contracts\RateLimitStorageInterface;

/**
 * Seleciona automaticamente o storage de rate limit:
 *   1. Redis  — se REDIS_HOST estiver configurado e ext-redis disponível
 *   2. File   — fallback para servidor único / desenvolvimento
 *
 * Uso no container (index.php):
 *   $container->bind(RateLimitStorageInterface::class,
 *       fn() => RateLimitStorageFactory::create(), true);
 */
final class RateLimitStorageFactory
{
    public static function create(?string $storageDir = null): RateLimitStorageInterface
    {
        $redisHost = trim($_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '');

        if ($redisHost !== '') {
            $redis = RedisRateLimitStorage::fromEnv();
            if ($redis !== null) {
                return $redis;
            }
            // Redis configurado mas indisponível — loga e cai para file
            error_log('[RateLimitStorageFactory] Redis configurado mas indisponível. Usando FileStorage.');
        }

        $dir = $storageDir ?? (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit');
        return new FileRateLimitStorage($dir);
    }
}
