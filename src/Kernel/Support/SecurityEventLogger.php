<?php

namespace Src\Kernel\Support;

/**
 * Logger estruturado de eventos de segurança para observabilidade.
 *
 * Emite JSON para stderr (capturado por Fail2Ban, CloudWatch, Datadog, etc.)
 * com campos padronizados para correlação e alertas automáticos.
 *
 * Campos obrigatórios em todo evento:
 *   timestamp, type, event, ip, request_id
 *
 * Campos opcionais por tipo:
 *   user_uuid, score, endpoint, user_agent, details
 *
 * Uso:
 *   SecurityEventLogger::threat('rate_limit.exceeded', $ip, ['score' => 80, 'endpoint' => '/api/login']);
 *   SecurityEventLogger::auth('login.failed', $ip, ['identifier' => 'user@x.com']);
 *   SecurityEventLogger::business('payment.duplicate', $ip, ['uuid' => $uuid]);
 */
final class SecurityEventLogger
{
    // Tipos de evento — usados pelo Fail2Ban e sistemas de alerta
    public const TYPE_THREAT   = 'THREAT';
    public const TYPE_AUTH     = 'AUTH';
    public const TYPE_BUSINESS = 'BUSINESS_LOGIC';
    public const TYPE_ABUSE    = 'ABUSE';
    public const TYPE_ALERT    = 'SECURITY_ALERT';

    // Thresholds para alertas automáticos
    private const ALERT_SCORE_THRESHOLD = 100;

    /**
     * Evento de ameaça (bot, scanner, rate limit, honeypot).
     */
    public static function threat(string $event, string $ip, array $details = []): void
    {
        self::emit(self::TYPE_THREAT, $event, $ip, $details);
        self::maybeAlert($event, $ip, $details);
    }

    /**
     * Evento de autenticação (login, logout, token, brute force).
     */
    public static function auth(string $event, string $ip, array $details = []): void
    {
        self::emit(self::TYPE_AUTH, $event, $ip, $details);
        self::maybeAlert($event, $ip, $details);
    }

    /**
     * Evento de lógica de negócio suspeita (replay, duplicate, bypass).
     */
    public static function business(string $event, string $ip, array $details = []): void
    {
        self::emit(self::TYPE_BUSINESS, $event, $ip, $details);
        self::maybeAlert($event, $ip, $details);
    }

    /**
     * Evento de abuso genérico.
     */
    public static function abuse(string $event, string $ip, array $details = []): void
    {
        self::emit(self::TYPE_ABUSE, $event, $ip, $details);
    }

    /**
     * Alerta crítico — sempre emitido independente de threshold.
     */
    public static function alert(string $event, string $ip, array $details = []): void
    {
        self::emit(self::TYPE_ALERT, $event, $ip, $details);
    }

    /**
     * Snapshot de score de ameaça — para dashboards e correlação.
     */
    public static function scoreSnapshot(string $ip, int $score, string $trigger): void
    {
        self::emit(self::TYPE_THREAT, 'threat.score.snapshot', $ip, [
            'score'   => $score,
            'trigger' => $trigger,
            'block'   => $score >= ThreatScorer::THRESHOLD_BLOCK,
            'delay'   => $score >= ThreatScorer::THRESHOLD_DELAY,
        ]);
    }

    // ── Internos ──────────────────────────────────────────────────────

    private static function emit(string $type, string $event, string $ip, array $details): void
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($env === 'testing') {
            return;
        }

        $line = json_encode(array_filter([
            'timestamp'  => date('Y-m-d\TH:i:sP'),
            'type'       => $type,
            'event'      => $event,
            'ip'         => $ip,
            'request_id' => self::requestId(),
            'endpoint'   => ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ] + $details, static fn($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }

    private static function maybeAlert(string $event, string $ip, array $details): void
    {
        $score = (int) ($details['score'] ?? 0);

        // Alerta automático por score alto
        if ($score >= self::ALERT_SCORE_THRESHOLD) {
            self::emit(self::TYPE_ALERT, 'auto.score.alert', $ip, [
                'trigger_event' => $event,
                'score'         => $score,
                'threshold'     => self::ALERT_SCORE_THRESHOLD,
            ]);
        }

        // Alerta automático por eventos críticos
        $criticalEvents = [
            'honeypot.hit',
            'brute_force.detected',
            'privilege.escalation.attempt',
            'business.replay.detected',
            'jwt.alg_none.attempt',
        ];

        if (in_array($event, $criticalEvents, true)) {
            self::emit(self::TYPE_ALERT, 'auto.critical.event', $ip, [
                'trigger_event' => $event,
                'details'       => $details,
            ]);
        }
    }

    private static function requestId(): ?string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    }
}
