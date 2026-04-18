<?php

namespace Src\Kernel\Support;

use PDO;
use Src\Kernel\Support\IpResolver;

/**
 * Registra eventos de segurança na tabela audit_logs.
 * Detecta comportamento suspeito e emite alertas.
 *
 * Eventos recomendados:
 *   auth.login.success, auth.login.failed, auth.logout,
 *   auth.token.refresh, auth.password.reset,
 *   user.created, user.updated, user.deleted,
 *   user.role.changed, module.toggled, admin.action
 */
class AuditLogger
{
    private ?PDO $pdo;
    private ?RequestContext $context;

    // Limiar de falhas de login por IP antes de emitir alerta
    private int $loginFailThreshold = 10;

    public function __construct(?PDO $pdo = null, ?RequestContext $context = null)
    {
        $this->pdo     = $pdo;
        $this->context = $context;
    }

    /**
     * Registra um evento de auditoria.
     */
    public function registrar(
        string $evento,
        ?string $usuarioUuid = null,
        array $contexto = [],
        ?string $ip = null
    ): void {
        $ip        = $ip ?? $this->resolveIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $endpoint  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/');

        $this->logStderr($evento, $usuarioUuid, $contexto, $ip);

        if ($this->pdo !== null) {
            $this->persistir($evento, $usuarioUuid, $contexto, $ip, $userAgent, $endpoint);
            $this->detectarComportamentoSuspeito($evento, $ip, $usuarioUuid);
        }
    }

    /**
     * Registra respostas HTTP de segurança (401, 403, 429) para observabilidade e Fail2Ban.
     * Deve ser chamado após o dispatch, com o status code da resposta.
     */
    public function registrarResposta(int $statusCode, string $uri, ?string $ip = null, array $contextoExtra = []): void
    {
        if (!in_array($statusCode, [401, 403, 429], true)) {
            return;
        }

        $ip = $ip ?? $this->resolveIp();

        $eventMap = [
            401 => 'http.unauthorized',
            403 => 'http.forbidden',
            429 => 'http.rate_limited',
        ];

        $contexto = array_merge(['status' => $statusCode, 'uri' => $uri], $contextoExtra);

        $line = json_encode([
            'timestamp'  => date('Y-m-d\TH:i:sP'),
            'type'       => 'SECURITY_RESPONSE',
            'request_id' => $this->context?->getRequestId(),
            'event'      => $eventMap[$statusCode],
            'status'     => $statusCode,
            'ip'         => $ip,
            'uri'        => $uri,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ] + $contextoExtra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);

        // Persiste no banco para análise posterior
        if ($this->pdo !== null) {
            $this->persistir(
                $eventMap[$statusCode],
                null,
                $contexto,
                $ip,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $uri
            );
        }
    }

    /**
     * Detecta padrões suspeitos e emite alertas via stderr.
     * Em produção, integrar com Slack/PagerDuty/SNS aqui.
     */
    private function detectarComportamentoSuspeito(string $evento, string $ip, ?string $usuarioUuid): void
    {
        if ($evento !== 'auth.login.failed' || $this->pdo === null) {
            return;
        }

        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            // Sintaxe de intervalo difere entre MySQL e PostgreSQL
            // Valor gerado internamente — sem input do usuário
            if ($driver === 'pgsql') {
                $sql = "SELECT COUNT(*) FROM audit_logs
                        WHERE evento = 'auth.login.failed'
                          AND ip = :ip
                          AND criado_em > NOW() - INTERVAL '5 minutes'";
            } else {
                $sql = "SELECT COUNT(*) FROM audit_logs
                        WHERE evento = 'auth.login.failed'
                          AND ip = :ip
                          AND criado_em > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':ip' => $ip]);
            $count = (int) $stmt->fetchColumn();

            if ($count >= $this->loginFailThreshold) {
                $this->emitirAlerta('BRUTE_FORCE_DETECTED', [
                    'ip'            => $ip,
                    'falhas_5min'   => $count,
                    'threshold'     => $this->loginFailThreshold,
                    'acao_sugerida' => 'Bloquear IP temporariamente',
                ]);
            }
        } catch (\Throwable) {
            // Falha silenciosa — detecção não deve quebrar o fluxo
        }
    }

    /**
     * Emite alerta de segurança.
     * Extensível: adicione webhook/SNS/Slack aqui.
     */
    private function emitirAlerta(string $tipo, array $dados): void
    {
        $line = json_encode([
            'timestamp'  => date('Y-m-d\TH:i:sP'),
            'type'       => 'SECURITY_ALERT',
            'request_id' => $this->context?->getRequestId(),
            'alert'      => $tipo,
            'dados'      => $dados,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);

        // Hook para webhook externo (configurável via .env)
        $webhookUrl = $_ENV['SECURITY_ALERT_WEBHOOK'] ?? '';
        if ($webhookUrl !== '') {
            $this->enviarWebhook($webhookUrl, $tipo, $dados);
        }
    }

    private function enviarWebhook(string $url, string $tipo, array $dados): void
    {
        $parsed = parse_url($url);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https') {
            return;
        }
        if ($this->isInternalHost($parsed['host'] ?? '')) {
            return;
        }

        if (!function_exists('curl_init')) {
            return;
        }

        try {
            $payload = (string) json_encode(['alert' => $tipo, 'dados' => $dados, 'timestamp' => date('c')]);
            $ch = curl_init($url);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => false, // sem redirects — previne SSRF
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Falha silenciosa
        }
    }

    private function isInternalHost(string $host): bool
    {
        // Bloqueia loopback, metadados AWS/GCP e ranges privados
        $blocked = ['localhost', '169.254.169.254', 'metadata.google.internal'];
        if (in_array(strtolower($host), $blocked, true)) {
            return true;
        }

        // Se o host já é um IP, valida diretamente sem DNS lookup
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // Para hostnames, não fazemos DNS lookup (evita SSRF via DNS rebinding e latência).
        // Bloqueia padrões de hostname interno comuns.
        $hostLower = strtolower($host);
        if (
            str_ends_with($hostLower, '.internal') ||
            str_ends_with($hostLower, '.local') ||
            str_ends_with($hostLower, '.localhost') ||
            str_starts_with($hostLower, '10.') ||
            str_starts_with($hostLower, '192.168.')
        ) {
            return true;
        }

        return false;
    }

    private function persistir(
        string $evento,
        ?string $usuarioUuid,
        array $contexto,
        string $ip,
        string $userAgent,
        string $endpoint
    ): void {
        if ($this->pdo === null) {
            return;
        }
        // Remove campos sensíveis do contexto antes de persistir
        $sensitiveKeys = ['senha', 'password', 'token', 'secret', 'hash', 'credit_card', 'cvv'];
        foreach ($sensitiveKeys as $key) {
            unset($contexto[$key]);
        }
        try {
            $sql = "INSERT INTO audit_logs
                        (evento, usuario_uuid, contexto, ip, user_agent, endpoint, criado_em)
                    VALUES
                        (:evento, :usuario_uuid, :contexto, :ip, :user_agent, :endpoint, NOW())";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':evento'       => $evento,
                ':usuario_uuid' => $usuarioUuid,
                ':contexto'     => json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip'           => $ip,
                ':user_agent'   => $userAgent,
                ':endpoint'     => substr($endpoint, 0, 255),
            ]);
        } catch (\Throwable $e) {
            error_log('[AuditLogger] Falha ao persistir: ' . $e->getMessage());
        }
    }

    private function logStderr(string $evento, ?string $usuarioUuid, array $contexto, string $ip): void
    {
        $line = json_encode([
            'timestamp'    => date('Y-m-d\TH:i:sP'),
            'type'         => 'AUDIT',
            'request_id'   => $this->context?->getRequestId(),
            'evento'       => $evento,
            'usuario_uuid' => $usuarioUuid,
            'ip'           => $ip,
            'contexto'     => $contexto,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }

    private function resolveIp(): string
    {
        return IpResolver::resolve();
    }
}