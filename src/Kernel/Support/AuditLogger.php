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

    // Limiar de falhas de login por IP antes de emitir alerta
    private int $loginFailThreshold = 10;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
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
    public function registrarResposta(int $statusCode, string $uri, ?string $ip = null): void
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

        $line = json_encode([
            'timestamp'  => date('Y-m-d\TH:i:sP'),
            'type'       => 'SECURITY_RESPONSE',
            'event'      => $eventMap[$statusCode],
            'status'     => $statusCode,
            'ip'         => $ip,
            'uri'        => $uri,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);

        // Persiste no banco para análise posterior
        if ($this->pdo !== null) {
            $this->persistir(
                $eventMap[$statusCode],
                null,
                ['status' => $statusCode, 'uri' => $uri],
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
        if ($evento !== 'auth.login.failed') {
            return;
        }

        try {
            // Conta falhas de login do mesmo IP nos últimos 5 minutos
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM audit_logs
                WHERE evento = 'auth.login.failed'
                  AND ip = :ip
                  AND criado_em > NOW() - INTERVAL '5 minutes'
            ");
            $stmt->execute([':ip' => $ip]);
            $count = (int) $stmt->fetchColumn();

            if ($count >= $this->loginFailThreshold) {
                $this->emitirAlerta('BRUTE_FORCE_DETECTED', [
                    'ip'           => $ip,
                    'falhas_5min'  => $count,
                    'threshold'    => $this->loginFailThreshold,
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
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'type'      => 'SECURITY_ALERT',
            'alert'     => $tipo,
            'dados'     => $dados,
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
        // Valida que a URL é HTTPS e não aponta para endereços internos (SSRF prevention)
        $parsed = parse_url($url);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https') {
            return;
        }
        $host = $parsed['host'] ?? '';
        // Bloqueia IPs privados, loopback e metadados de cloud
        if ($this->isInternalHost($host)) {
            return;
        }

        try {
            $payload = json_encode(['alert' => $tipo, 'dados' => $dados, 'timestamp' => date('c')]);
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                    'content' => $payload,
                    'timeout' => 3,
                    'ignore_errors' => true,
                ],
            ]);
            file_get_contents($url, false, $ctx);
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
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: '');
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
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