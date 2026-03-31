<?php
namespace Src\Kernel\Middlewares;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Protege rotas que aceitam tanto token de usuário quanto token de API.
 * Sempre valida a assinatura JWT antes de inspecionar o payload.
 */
class RouteProtectionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $roles = $request->attribute('roles', []);

        $secretApi  = trim((string)($_ENV['JWT_API_SECRET']  ?? getenv('JWT_API_SECRET')  ?? ''));
        $secretUser = trim((string)($_ENV['JWT_SECRET']      ?? getenv('JWT_SECRET')      ?? ''));

        if ($secretApi === '' || $secretUser === '') {
            return Response::json(['error' => 'JWT secrets não configurados.'], 500);
        }

        // Extrai token do header Authorization Bearer
        $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $jwt = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            $jwt = trim($m[1]);
        }

        // Fallback: X-API-KEY header (apenas para token de API)
        $apiKeyHeader = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));

        // Tenta validar como token de API (JWT_API_SECRET)
        $decodedApi  = null;
        $decodedUser = null;

        if ($jwt !== '') {
            try {
                $decodedApi = JWT::decode($jwt, new Key($secretApi, 'HS256'));
            } catch (\Throwable) {}

            try {
                $decodedUser = JWT::decode($jwt, new Key($secretUser, 'HS256'));
            } catch (\Throwable) {}
        } elseif ($apiKeyHeader !== '') {
            // X-API-KEY só aceita token de API
            try {
                $decodedApi = JWT::decode($apiKeyHeader, new Key($secretApi, 'HS256'));
            } catch (\Throwable) {}
        }

        // Rota com restrição de papel (role-based)
        if (!empty($roles)) {
            if ($decodedUser === null) {
                return Response::json(['error' => 'Token de usuário inválido ou ausente.'], 401);
            }
            $userRole = $decodedUser->nivel_acesso ?? null;
            if (!in_array($userRole, $roles, true)) {
                return Response::json(['error' => 'Acesso restrito para este papel.'], 403);
            }
            return $next($request);
        }

        // Rota de API pura: exige token de API válido com tipo='api'
        if ($decodedApi !== null) {
            $isApiToken = !empty($decodedApi->api_access) || ($decodedApi->tipo ?? '') === 'api';
            if ($isApiToken) {
                return $next($request);
            }
        }

        return Response::json(['error' => 'Acesso negado: token de API inválido ou ausente.'], 403);
    }
}
