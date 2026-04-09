<?php

namespace Src\Kernel\Support;

use Src\Kernel\Contracts\RateLimitStorageInterface;
use Src\Kernel\Support\Storage\RateLimitStorageFactory;

/**
 * Proteção contra race conditions em operações críticas.
 *
 * Implementa distributed lock usando o storage disponível (Redis ou File).
 * Em Redis usa SETNX atômico. Em File usa flock exclusivo.
 *
 * Uso:
 *   $lock = IdempotencyLock::acquire("pagamento:{$uuid}", 30);
 *   if (!$lock) {
 *       return Response::json(['error' => 'Operação em andamento.'], 409);
 *   }
 *   try {
 *       // operação crítica
 *   } finally {
 *       $lock->release();
 *   }
 *
 * Também protege contra replay de operações idempotentes:
 *   $lock = IdempotencyLock::idempotent("req:{$idempotencyKey}", 86400);
 *   if ($lock->alreadyExecuted()) {
 *       return $lock->cachedResponse();
 *   }
 */
final class IdempotencyLock
{
    private bool $acquired = false;
    private bool $alreadyExecuted = false;
    private mixed $cachedResult = null;

    private function __construct(
        private string $key,
        private RateLimitStorageInterface $storage,
        private ?string $lockDir = null
    ) {}

    /**
     * Tenta adquirir lock exclusivo para operação crítica.
     * Retorna null se o lock já está em uso (operação em andamento).
     *
     * Usa SET NX atômico (Redis) ou flock exclusivo (File).
     * Elimina a race condition do INCR anterior.
     *
     * @param int $ttlSeconds  tempo máximo que o lock pode ficar ativo
     */
    public static function acquire(string $key, int $ttlSeconds = 30): ?self
    {
        $storage = RateLimitStorageFactory::create();
        $lock    = new self($key, $storage);

        if (!$storage->setNx($key, $ttlSeconds)) {
            return null; // lock em uso por outra instância
        }

        $lock->acquired = true;
        return $lock;
    }

    /**
     * Verifica se uma operação idempotente já foi executada.
     * Usa a chave para detectar replay dentro do TTL.
     *
     * @param int $ttlSeconds  janela de idempotência (ex: 86400 = 24h)
     */
    public static function idempotent(string $key, int $ttlSeconds = 86400): self
    {
        $storage = RateLimitStorageFactory::create();
        $lock    = new self($key, $storage);

        $count = $storage->addWithTtl('idem:' . $key, 1, $ttlSeconds);
        if ($count > 1) {
            $lock->alreadyExecuted = true;
        }

        $lock->acquired = true;
        return $lock;
    }

    public function alreadyExecuted(): bool
    {
        return $this->alreadyExecuted;
    }

    public function setCachedResponse(mixed $result): void
    {
        $this->cachedResult = $result;
    }

    public function cachedResponse(): mixed
    {
        return $this->cachedResult;
    }

    public function release(): void
    {
        if ($this->acquired) {
            $this->storage->deleteLock($this->key);
            $this->acquired = false;
        }
    }

    public function __destruct()
    {
        // Garante liberação mesmo em caso de exceção não capturada
        $this->release();
    }
}
