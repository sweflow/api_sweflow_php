<?php

namespace Src\Kernel\Support;

use Src\Kernel\Contracts\RateLimitStorageInterface;
use Src\Kernel\Support\Storage\RateLimitStorageFactory;

/**
 * Threat Scoring por IP — acumula pontos de comportamento suspeito.
 * Suporta Redis (distribuído) e File (servidor único) via RateLimitStorageInterface.
 *
 * Pontuação:
 *   honeypot hit  → +100
 *   UA malicioso  → +50
 *   login falhou  → +30
 *   rate limit    → +20
 *   sem UA        → +15
 *
 * Thresholds:
 *   score >= 50  → delay progressivo (2s, 5s, 10s)
 *   score >= 150 → bloqueia (403)
 *
 * TTL: 1 hora — score zera automaticamente.
 */
class ThreatScorer
{
    public const SCORE_HONEYPOT    = 100;
    public const SCORE_MALICIOUS_UA = 50;
    public const SCORE_LOGIN_FAIL  = 30;
    public const SCORE_RATE_LIMIT  = 20;
    public const SCORE_NO_UA       = 15;

    public const THRESHOLD_DELAY = 50;
    public const THRESHOLD_BLOCK = 150;

    private const TTL = 3600; // 1 hora

    private RateLimitStorageInterface $storage;

    public function __construct(?RateLimitStorageInterface $storage = null)
    {
        $this->storage = $storage ?? RateLimitStorageFactory::create(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'threat'
        );
    }

    /** Adiciona pontos ao score do IP e retorna o score total. */
    public function add(string $ip, int $points): int
    {
        return $this->storage->addWithTtl($this->key($ip), $points, self::TTL);
    }

    /** Retorna o score atual do IP (0 se não existir ou expirado). */
    public function get(string $ip): int
    {
        return $this->storage->get($this->key($ip));
    }

    /** Retorna true se o IP deve ser bloqueado. */
    public function shouldBlock(string $ip): bool
    {
        return $this->get($ip) >= self::THRESHOLD_BLOCK;
    }

    /** Retorna o delay em segundos que deve ser aplicado (0 = nenhum). */
    public function delaySeconds(string $ip): int
    {
        $score = $this->get($ip);
        if ($score >= self::THRESHOLD_BLOCK) {
            return 10;
        }
        if ($score >= 100) {
            return 5;
        }
        if ($score >= self::THRESHOLD_DELAY) {
            return 2;
        }
        return 0;
    }

    private function key(string $ip): string
    {
        return 'threat:' . $ip;
    }
}
