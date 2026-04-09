<?php

namespace Src\Kernel\Support\Storage;

use Src\Kernel\Contracts\RateLimitStorageInterface;

/**
 * Storage de rate limit baseado em arquivos JSON com flock.
 * Funciona em servidor único. Para ambiente distribuído use RedisRateLimitStorage.
 */
class FileRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private string $storageDir) {}

    public function increment(string $key, int $windowSeconds): array
    {
        $this->ensureDir();
        $file = $this->path($key);
        $now  = time();

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return [0, $now + $windowSeconds];
        }

        flock($fp, LOCK_EX);
        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw !== '' ? (json_decode($raw, true) ?? []) : [];

        $resetAt = (int) ($data['reset_at'] ?? 0);
        $count   = (int) ($data['count']    ?? 0);

        if ($now >= $resetAt) {
            $resetAt = $now + $windowSeconds;
            $count   = 0;
        }

        $count++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(['count' => $count, 'reset_at' => $resetAt]));
        flock($fp, LOCK_UN);
        fclose($fp);

        return [$count, $resetAt];
    }

    public function get(string $key): int
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return 0;
        }
        $raw  = @file_get_contents($file);
        $data = $raw ? (json_decode($raw, true) ?? []) : [];

        $ttlUntil = (int) ($data['ttl_until'] ?? 0);

        if ($ttlUntil > 0 && time() > $ttlUntil) {
            return 0;
        }

        return (int) ($data['value'] ?? 0);
    }

    public function addWithTtl(string $key, int $delta, int $ttlSeconds): int
    {
        $this->ensureDir();
        $file = $this->path($key);
        $now  = time();

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return $delta;
        }

        flock($fp, LOCK_EX);
        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw !== '' ? (json_decode($raw, true) ?? []) : [];

        $ttlUntil = (int) ($data['ttl_until'] ?? 0);
        $value    = (int) ($data['value']     ?? 0);

        // Expirou — reinicia
        if ($ttlUntil > 0 && $now > $ttlUntil) {
            $value    = 0;
            $ttlUntil = 0;
        }

        $value   += $delta;
        $ttlUntil = $ttlUntil > 0 ? $ttlUntil : ($now + $ttlSeconds);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(['value' => $value, 'ttl_until' => $ttlUntil]));
        flock($fp, LOCK_UN);
        fclose($fp);

        return $value;
    }

    public function delete(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function purgeExpired(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }
        $now = time();
        $dh  = opendir($this->storageDir);
        if ($dh === false) {
            return;
        }
        $checked = 0;
        while (($file = readdir($dh)) !== false && $checked < 500) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            $path = $this->storageDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $raw  = @file_get_contents($path);
            $data = $raw ? (json_decode($raw, true) ?? []) : [];

            $resetAt  = (int) ($data['reset_at']  ?? 0);
            $ttlUntil = (int) ($data['ttl_until'] ?? 0);

            $expired = ($resetAt  > 0 && $now > $resetAt  + 300)
                    || ($ttlUntil > 0 && $now > $ttlUntil + 300);

            if ($expired && is_writable($path)) {
                @unlink($path);
            }
            $checked++;
        }
        closedir($dh);
    }

    private function path(string $key): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * SET NX via flock: cria o arquivo de lock apenas se não existir ou estiver expirado.
     * Atômico no nível de processo único (flock). Para multi-servidor use Redis.
     */
    public function setNx(string $key, int $ttlSeconds): bool
    {
        $this->ensureDir();
        $file = $this->storageDir . DIRECTORY_SEPARATOR . 'nx_' . hash('sha256', $key) . '.json';
        $now  = time();

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return false;
        }

        flock($fp, LOCK_EX);
        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw !== '' ? (json_decode($raw, true) ?? []) : [];

        $expiresAt = (int) ($data['expires_at'] ?? 0);

        // Já existe e não expirou — lock em uso
        if ($expiresAt > 0 && $now < $expiresAt) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        // Adquire: escreve novo TTL
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(['expires_at' => $now + $ttlSeconds]));
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    public function deleteLock(string $key): void
    {
        $file = $this->storageDir . DIRECTORY_SEPARATOR . 'nx_' . hash('sha256', $key) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
