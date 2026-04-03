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
     *
     * Estratégia de detecção (em ordem de confiança):
     * 1. $_SERVER['HTTPS'] = 'on'  — conexão TLS direta ao PHP
     * 2. SERVER_PORT = 443         — porta TLS direta
     * 3. X-Forwarded-Proto = https — proxy reverso confiável:
     *    - TRUST_PROXY=true: aceita de qualquer origem (configuração explícita do admin)
     *    - TRUST_PROXY=false: aceita APENAS de loopback (127.0.0.1, ::1)
     *      pois o Nginx local sempre usa loopback para fazer proxy ao PHP.
     *      Isso impede spoofing externo sem exigir TRUST_PROXY=true.
     */
    public static function isHttps(): bool
    {
        // Conexão TLS direta
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // Porta 443 explícita
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        // X-Forwarded-Proto — verifica origem antes de confiar
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto       = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
            $trustProxy  = self::bool('TRUST_PROXY', 'false');
            $remoteAddr  = $_SERVER['REMOTE_ADDR'] ?? '';
            $isLoopback  = in_array($remoteAddr, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
                           || strncmp($remoteAddr, '127.', 4) === 0;

            // Aceita se: TRUST_PROXY=true OU requisição vem do loopback (Nginx local)
            if ($proto === 'https' && ($trustProxy || $isLoopback)) {
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
