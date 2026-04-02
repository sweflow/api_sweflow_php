<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class AdminOnlyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $isPage = !str_starts_with($request->getUri(), '/api/');

        // Caso 1: token de API puro (JWT_API_SECRET com tipo:api) — acesso total
        if ($request->attribute('api_token') === true) {
            return $this->prosseguir($next, $request);
        }

        $payload = $request->attribute('auth_payload');
        $usuario = $request->attribute('auth_user');

        if ($payload === null || $usuario === null) {
            return $isPage
                ? new Response('', 302, ['Location' => '/'])
                : Response::json(['error' => 'Autenticação obrigatória.'], 401);
        }

        $nivel        = $payload->nivel_acesso ?? null;
        $nivelUsuario = method_exists($usuario, 'getNivelAcesso') ? $usuario->getNivelAcesso() : null;

        // Caso 2: token de usuário admin_system assinado com JWT_API_SECRET.
        // O AuthHybridMiddleware já validou e injetou o atributo 'token_signed_with_api_secret'.
        // Fallback: verifica diretamente nos headers/cookie para compatibilidade.
        $assinadoComApiSecret = $request->attribute('token_signed_with_api_secret') === true
            || $this->verificarTokenApiSecret();

        if ($nivel === 'admin_system' && $nivelUsuario === 'admin_system' && $assinadoComApiSecret) {
            return $this->prosseguir($next, $request);
        }

        return $isPage
            ? new Response('', 302, ['Location' => '/'])
            : Response::json(['error' => 'Acesso restrito.'], 403);
    }

    private function prosseguir(callable $next, Request $request): Response
    {
        $result = $next($request);
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        return Response::json([], 204);
    }

    /**
     * Fallback: verifica se o token presente nos headers/cookie foi assinado com JWT_API_SECRET.
     * Aceita cookie auth_token, Authorization Bearer e X-API-KEY.
     */
    private function verificarTokenApiSecret(): bool
    {
        $apiSecret = trim((string) ($_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? ''));
        if ($apiSecret === '') {
            return false;
        }

        $token = $this->extrairToken();
        if ($token === '') {
            return false;
        }

        try {
            \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($apiSecret, 'HS256'));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Extrai o token dos mesmos vetores que o AuthHybridMiddleware:
     * cookie auth_token > Authorization Bearer > X-API-KEY
     */
    private function extrairToken(): string
    {
        $cookieToken = isset($_COOKIE['auth_token']) ? trim((string) $_COOKIE['auth_token']) : '';
        if ($cookieToken !== '') {
            return $cookieToken;
        }

        $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)/i', $bearer, $m)) {
            $bearerToken = trim($m[1]);
            if ($bearerToken !== '') {
                return $bearerToken;
            }
        }

        // X-API-KEY também pode carregar token de usuário admin_system
        $apiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($apiKey !== '') {
            return $apiKey;
        }

        return '';
    }
}
