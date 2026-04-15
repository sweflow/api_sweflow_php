<?php

namespace Src\Kernel\Support;

/**
 * Centraliza a leitura das configurações de cookie do .env.
 * Garante que COOKIE_SECURE, COOKIE_HTTPONLY e COOKIE_SAMESITE
 * sejam aplicados de forma consistente em todo o sistema.
 */
class CookieConfig
{
    /**
     * Retorna true se a requisição atual chegou via HTTPS.
     * Mesma lógica do RequestContext::detectSecure() — mantida aqui
     * para uso estático em contextos sem DI (CLI, testes, código legado).
     * Em código novo, prefira injetar RequestContext e usar isSecure().
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $proto      = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted    = self::bool('TRUST_PROXY', 'false');
        $isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
                      || strncmp($remoteAddr, '127.', 4) === 0;

        if ($proto === 'https' && ($trusted || $isLoopback)) {
            return true;
        }
        if ($trusted) {
            $appUrl = strtolower(trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '')));
            if (str_starts_with($appUrl, 'https://')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna true se o login deve ser bloqueado em HTTP.
     * Condição: COOKIE_SECURE=true E COOKIE_HTTPONLY=true.
     */
    public static function requiresHttps(): bool
    {
        return self::bool('COOKIE_SECURE', 'false') && self::bool('COOKIE_HTTPONLY', 'true');
    }

    public static function options(int $expires = 0, string $path = '/'): array
    {
        $secure   = self::bool('COOKIE_SECURE', 'false');
        $httponly = self::bool('COOKIE_HTTPONLY', 'true');
        $sameSite = self::sameSite('COOKIE_SAMESITE', 'Lax');
        $domain   = trim((string) ($_ENV['COOKIE_DOMAIN'] ?? (getenv('COOKIE_DOMAIN') ?: null) ?? ''));
        $domain   = (string) preg_replace('#^https?://#', '', $domain);

        // SameSite=None exige Secure=true; se Secure=false, cai para Lax
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        $opts = [
            'expires'  => $expires,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $sameSite,
        ];

        if ($domain !== '') {
            $opts['domain'] = $domain;
        }

        return $opts;
    }

    private static function bool(string $key, string $default): bool
    {
        $raw = $_ENV[$key] ?? (getenv($key) ?: null) ?? $default;
        $val = strtolower(trim((string) $raw));
        return in_array($val, ['1', 'true', 'on', 'yes'], true);
    }

    private static function sameSite(string $key, string $default): string
    {
        $raw = $_ENV[$key] ?? (getenv($key) ?: null) ?? $default;
        $val = ucfirst(strtolower(trim((string) $raw)));
        return in_array($val, ['Lax', 'Strict', 'None'], true) ? $val : 'Lax';
    }
}
