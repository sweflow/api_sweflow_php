<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenResolverInterface;
use Src\Kernel\Http\Request\Request;

/**
 * Resolve token do cookie auth_token, com fallback para Authorization: Bearer.
 * Padrão para rotas de página (HTML) e AuthCookieMiddleware.
 *
 * Lê primeiro do objeto Request (cookies injetados pelo RequestFactory),
 * com fallback para $_COOKIE para compatibilidade com contextos legados.
 */
final class CookieTokenResolver implements TokenResolverInterface
{
    private const MAX_TOKEN_LENGTH = 2048;

    public function resolve(Request $request): string
    {
        // 1. Cookie auth_token do Request (testável, sem superglobal)
        $cookie = $request->cookies['auth_token'] ?? '';
        if (!is_string($cookie)) {
            $cookie = '';
        }
        $cookie = trim($cookie);
        if ($cookie !== '' && strlen($cookie) <= self::MAX_TOKEN_LENGTH) {
            return $cookie;
        }

        // 2. Fallback $_COOKIE — cobre contextos onde Request não foi construído via RequestFactory
        $cookie = $_COOKIE['auth_token'] ?? '';
        if (is_string($cookie)) {
            $cookie = trim($cookie);
            if ($cookie !== '' && strlen($cookie) <= self::MAX_TOKEN_LENGTH) {
                return $cookie;
            }
        }

        // 3. Authorization: Bearer do Request
        $auth = $request->header('Authorization') ?? '';
        if ($auth !== '' && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            $token = $m[1];
            if (strlen($token) <= self::MAX_TOKEN_LENGTH) {
                return $token;
            }
        }

        // 4. Fallback $_SERVER
        $serverAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($serverAuth !== '' && preg_match('/Bearer\s+(\S+)/i', $serverAuth, $m)) {
            $token = $m[1];
            if (strlen($token) <= self::MAX_TOKEN_LENGTH) {
                return $token;
            }
        }

        return '';
    }
}
