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

        // Caso 2: token de usuário admin_system DEVE ter sido assinado com JWT_API_SECRET.
        // Confia exclusivamente no atributo injetado pelo AuthHybridMiddleware —
        // não re-decodifica o token aqui para evitar inconsistências.
        $assinadoComApiSecret = $request->attribute('token_signed_with_api_secret') === true;

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

}
