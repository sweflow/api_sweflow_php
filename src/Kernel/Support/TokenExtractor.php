<?php

namespace Src\Kernel\Support;

/**
 * Extrai tokens JWT de requisições HTTP de forma centralizada.
 *
 * Fontes suportadas (em ordem de prioridade):
 *   1. Cookie auth_token
 *   2. Authorization: Bearer <token>
 *   3. X-API-KEY header (apenas para tokens de API)
 */
final class TokenExtractor
{
    /** Retorna o token de autenticação (cookie ou Bearer), ou string vazia. */
    public static function fromRequest(): string
    {
        $cookie = $_COOKIE['auth_token'] ?? '';
        if (is_string($cookie) && trim($cookie) !== '') {
            return trim($cookie);
        }
        return self::fromBearer();
    }

    /** Retorna apenas o Bearer token do header Authorization, ou string vazia. */
    public static function fromBearer(): string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** Retorna o token do header X-API-KEY, ou string vazia. */
    public static function fromApiKey(): string
    {
        return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    /**
     * Retorna o token para autenticação de API:
     * X-API-KEY → Bearer → cookie.
     */
    public static function fromApiRequest(): string
    {
        $apiKey = self::fromApiKey();
        if ($apiKey !== '') {
            return $apiKey;
        }
        return self::fromRequest();
    }
}
