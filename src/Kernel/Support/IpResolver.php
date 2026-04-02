<?php

namespace Src\Kernel\Support;

/**
 * Resolve o IP real do cliente de forma centralizada.
 *
 * Ordem de prioridade (quando TRUST_PROXY=true):
 *   1. CF-Connecting-IP  (Cloudflare)
 *   2. X-Real-IP         (nginx proxy_pass)
 *   3. X-Forwarded-For   (primeiro IP da cadeia)
 *   4. REMOTE_ADDR       (fallback)
 *
 * Normalização:
 *   - ::1  → 127.0.0.1  (IPv6 loopback → IPv4)
 *   - ::ffff:x.x.x.x → x.x.x.x  (IPv4-mapped IPv6 → IPv4 puro)
 */
class IpResolver
{
    /**
     * Retorna o IP do cliente resolvido.
     */
    public static function resolve(): string
    {
        $trustProxy = strtolower(trim($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?: 'false'));

        if (in_array($trustProxy, ['1', 'true', 'yes'], true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                $val = $_SERVER[$header] ?? '';
                if ($val === '') {
                    continue;
                }
                // X-Forwarded-For pode ter múltiplos IPs: pega o primeiro (cliente original)
                $ip = trim(explode(',', $val)[0]);
                $ip = self::normalize($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return self::normalize($remoteAddr);
    }

    /**
     * Normaliza endereços IPv6 especiais para IPv4 legível.
     *
     * ::1              → 127.0.0.1   (loopback IPv6)
     * ::ffff:x.x.x.x  → x.x.x.x    (IPv4-mapped IPv6)
     */
    public static function normalize(string $ip): string
    {
        // IPv6 loopback
        if ($ip === '::1') {
            return '127.0.0.1';
        }

        // IPv4-mapped IPv6: ::ffff:192.168.1.1
        if (stripos($ip, '::ffff:') === 0) {
            $candidate = substr($ip, 7);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $candidate;
            }
        }

        return $ip;
    }
}
