<?php

namespace Src\Kernel\Support;

/**
 * Contexto imutável da requisição atual.
 *
 * Regras arquiteturais:
 *   - NÃO depende de nenhuma outra classe do sistema
 *   - NÃO contém lógica de negócio
 *   - É o único ponto de verdade para: HTTPS, IP do cliente, proxy trust
 *   - Instanciado uma vez por request no container (singleton)
 *
 * Uso via injeção de dependência:
 *   class MeuServico {
 *       public function __construct(private RequestContext $ctx) {}
 *       public function handle(): void {
 *           $ip     = $this->ctx->getClientIp();
 *           $secure = $this->ctx->isSecure();
 *       }
 *   }
 */
class RequestContext
{
    private string  $requestId;
    private bool    $secure;
    private string  $clientIp;
    private bool    $trustedProxy;
    private ?string $tenantId = null;
    private ?string $userId   = null;
    private array   $meta     = [];

    public function __construct()
    {
        $this->requestId    = bin2hex(random_bytes(16));
        $this->trustedProxy = self::detectTrustedProxy();
        $this->secure       = self::detectSecure($this->trustedProxy);
        $this->clientIp     = self::detectClientIp($this->trustedProxy);
    }

    // ── Getters de request ────────────────────────────────────────────────

    public function getRequestId(): string { return $this->requestId; }
    public function isSecure(): bool       { return $this->secure; }
    public function getClientIp(): string  { return $this->clientIp; }
    public function isTrustedProxy(): bool { return $this->trustedProxy; }

    // ── Tenant / User (preenchidos pelos middlewares de auth) ─────────────

    public function setTenantId(?string $id): void { $this->tenantId = $id; }
    public function getTenantId(): ?string          { return $this->tenantId; }
    public function setUserId(?string $id): void    { $this->userId = $id; }
    public function getUserId(): ?string            { return $this->userId; }

    // ── Meta genérico ─────────────────────────────────────────────────────

    public function set(string $key, mixed $value): void         { $this->meta[$key] = $value; }
    public function get(string $key, mixed $default = null): mixed { return $this->meta[$key] ?? $default; }

    public function toArray(): array
    {
        return array_filter([
            'request_id' => $this->requestId,
            'tenant_id'  => $this->tenantId,
            'user_id'    => $this->userId,
            'ip'         => $this->clientIp,
            'secure'     => $this->secure,
            'meta'       => $this->meta ?: null,
        ], fn($v) => $v !== null);
    }

    // ── Detecção pura (sem dependências externas) ─────────────────────────

    private static function detectTrustedProxy(): bool
    {
        $val = strtolower(trim((string) ($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?: 'false')));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Detecta HTTPS com suporte a:
     *   - TLS direto
     *   - FastCGI com env HTTPS=on (Caddy: env HTTPS on)
     *   - X-Forwarded-Proto de proxy confiável
     *   - Fallback: TRUST_PROXY=true + APP_URL https:// (socket Unix sem REMOTE_ADDR)
     */
    private static function detectSecure(bool $trustedProxy): bool
    {
        // 1. TLS direto ou FastCGI com env HTTPS=on
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // 2. Porta 443 explícita
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // 3. X-Forwarded-Proto de proxy confiável ou loopback
        $proto      = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
                      || strncmp($remoteAddr, '127.', 4) === 0;

        if ($proto === 'https' && ($trustedProxy || $isLoopback)) {
            return true;
        }
        // 4. TRUST_PROXY=true + APP_URL https:// — cobre FastCGI via socket Unix
        if ($trustedProxy) {
            $appUrl = strtolower(trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '')));
            if (str_starts_with($appUrl, 'https://')) {
                return true;
            }
        }

        return false;
    }

    private static function detectClientIp(bool $trustedProxy): string
    {
        if ($trustedProxy) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                $val = trim($_SERVER[$h] ?? '');
                if ($val === '') continue;
                $ip = self::normalizeIp(trim(explode(',', $val)[0]));
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return self::normalizeIp($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function normalizeIp(string $ip): string
    {
        if ($ip === '::1') return '127.0.0.1';
        if (stripos($ip, '::ffff:') === 0) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $v4;
        }
        return $ip;
    }
}
