<?php

namespace Src\Kernel\Contracts;

/**
 * Contrato para storage de rate limiting e threat scoring.
 * Implementações: FileStorage (padrão) e RedisStorage (produção distribuída).
 */
interface RateLimitStorageInterface
{
    /**
     * Incrementa o contador da chave e retorna [count, resetAt].
     * Se a janela expirou, reinicia o contador.
     *
     * @return array{0: int, 1: int} [count, resetAt]
     */
    public function increment(string $key, int $windowSeconds): array;

    /**
     * Retorna o valor inteiro armazenado na chave (0 se não existir ou expirado).
     */
    public function get(string $key): int;

    /**
     * Adiciona delta ao valor da chave com TTL. Retorna o novo valor.
     */
    public function addWithTtl(string $key, int $delta, int $ttlSeconds): int;

    /**
     * Remove a chave.
     */
    public function delete(string $key): void;

    /**
     * Remove chaves expiradas (no-op em Redis, necessário para file storage).
     */
    public function purgeExpired(): void;

    /**
     * SET NX atômico: define a chave com TTL apenas se ela não existir.
     * Retorna true se adquiriu (chave não existia), false se já existia.
     * Garante atomicidade real para distributed locks.
     */
    public function setNx(string $key, int $ttlSeconds): bool;

    /**
     * Remove o lock criado por setNx.
     * Separado de delete() para não colidir com chaves de rate limit.
     */
    public function deleteLock(string $key): void;
}
