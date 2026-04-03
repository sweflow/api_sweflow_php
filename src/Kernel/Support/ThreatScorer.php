<?php

namespace Src\Kernel\Support;

/**
 * Threat Scoring por IP — acumula pontos de comportamento suspeito.
 *
 * Pontuação:
 *   honeypot hit      → +100  (ban imediato via fail2ban)
 *   UA malicioso      → +50
 *   login falhou      → +30
 *   rate limit hit    → +20
 *   sem User-Agent    → +15
 *
 * Thresholds:
 *   score >= 50  → delay progressivo (2s, 5s, 10s)
 *   score >= 150 → bloqueia (403)
 *
 * Armazenamento: arquivo JSON em storage/threat/ (mesmo padrão do RateLimitMiddleware).
 * TTL: 1 hora — score zera automaticamente após inatividade.
 */
class ThreatScorer
{
    public const SCORE_HONEYPOT   = 100;
    public const SCORE_MALICIOUS_UA = 50;
    public const SCORE_LOGIN_FAIL = 30;
    public const SCORE_RATE_LIMIT = 20;
    public const SCORE_NO_UA      = 15;

    public const THRESHOLD_DELAY = 50;
    public const THRESHOLD_BLOCK = 150;

    private const TTL     = 3600; // 1 hora
    private const DIR_KEY = 'threat';

    private string $storageDir;

    public function __construct()
    {
        $this->storageDir = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . self::DIR_KEY;
    }

    /** Adiciona pontos ao score do IP e retorna o score total. */
    public function add(string $ip, int $points): int
    {
        $data = $this->read($ip);
        $data['score'] += $points;
        $data['hits']++;
        $data['last_seen'] = time();
        $this->write($ip, $data);
        return $data['score'];
    }

    /** Retorna o score atual do IP (0 se não existir ou expirado). */
    public function get(string $ip): int
    {
        return $this->read($ip)['score'];
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

    private function read(string $ip): array
    {
        $file = $this->filePath($ip);
        if (!is_file($file)) {
            return ['score' => 0, 'hits' => 0, 'last_seen' => 0];
        }

        $fp = fopen($file, 'r');
        if (!$fp) {
            return ['score' => 0, 'hits' => 0, 'last_seen' => 0];
        }

        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($raw ?: '', true) ?? [];
        $lastSeen = (int) ($data['last_seen'] ?? 0);

        // Expirou — trata como zero
        if ($lastSeen > 0 && (time() - $lastSeen) > self::TTL) {
            return ['score' => 0, 'hits' => 0, 'last_seen' => 0];
        }

        return [
            'score'     => (int) ($data['score']     ?? 0),
            'hits'      => (int) ($data['hits']       ?? 0),
            'last_seen' => $lastSeen,
        ];
    }

    private function write(string $ip, array $data): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }

        $file = $this->filePath($ip);
        $fp   = fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function filePath(string $ip): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . hash('sha256', 'threat:' . $ip) . '.json';
    }
}
