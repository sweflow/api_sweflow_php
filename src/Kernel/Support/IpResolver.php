<?php

namespace Src\Kernel\Support;

/**
 * Resolve o IP real do cliente.
 *
 * Em código novo, prefira injetar RequestContext e usar getClientIp().
 * Este helper estático existe para compatibilidade com código legado
 * que não usa DI (middlewares, helpers, código estático).
 *
 * A lógica de resolução vive em RequestContext::detectClientIp() —
 * aqui apenas replicamos o comportamento para o caso estático.
 */
class IpResolver
{
    public static function resolve(): string
    {
        $trusted = in_array(
            strtolower(trim((string) ($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?: 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if ($trusted) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                $val = trim($_SERVER[$h] ?? '');
                if ($val === '') continue;
                $ip = self::normalize(trim(explode(',', $val)[0]));
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return self::normalize($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function normalize(string $ip): string
    {
        if ($ip === '::1') return '127.0.0.1';
        if (stripos($ip, '::ffff:') === 0) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $v4;
        }
        return $ip;
    }
}
