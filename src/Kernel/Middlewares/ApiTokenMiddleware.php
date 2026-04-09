<?php

namespace Src\Kernel\Middlewares;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\TokenExtractor;

/**
 * Valida tokens JWT de API (JWT_API_SECRET).
 * Aceita via header X-API-KEY ou Authorization Bearer.
 */
class ApiTokenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $token = TokenExtractor::fromApiRequest();
        if ($token === '') {
            return Response::json(['error' => 'Token de API ausente.'], 401);
        }

        $secret = trim((string) ($_ENV['JWT_API_SECRET'] ?? (getenv('JWT_API_SECRET') ?: '')));
        if ($secret === '') {
            return Response::json(['error' => 'JWT_API_SECRET não configurado no servidor.'], 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable) {
            return Response::json(['error' => 'Token de API inválido ou expirado.'], 401);
        }

        $isApiToken = !empty($payload->api_access) || ($payload->tipo ?? '') === 'api';
        if (!$isApiToken) {
            return Response::json(['error' => 'Token válido, mas não é de API.'], 403);
        }

        return $next($request);
    }
}
