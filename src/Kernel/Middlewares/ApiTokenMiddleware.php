<?php

namespace Src\Kernel\Middlewares;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Valida tokens JWT de API (JWT_API_SECRET).
 * Aceita via header X-API-KEY ou Authorization Bearer.
 * Nunca compara o token diretamente com o secret — sempre valida como JWT.
 */
class ApiTokenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $this->extrairToken();

        if ($token === '') {
            return Response::json(['error' => 'Token de API ausente.'], 401);
        }

        $secret = trim((string)($_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? ''));
        if ($secret === '') {
            return Response::json(['error' => 'JWT_API_SECRET não configurado no servidor.'], 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable) {
            return Response::json(['error' => 'Token de API inválido ou expirado.'], 401);
        }

        // Garante que é um token de API, não de usuário
        $isApiToken = !empty($payload->api_access) || ($payload->tipo ?? '') === 'api';
        if (!$isApiToken) {
            return Response::json(['error' => 'Token válido, mas não é de API.'], 403);
        }

        return $next($request);
    }

    private function extrairToken(): string
    {
        $fromHeader = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }

        return '';
    }
}
