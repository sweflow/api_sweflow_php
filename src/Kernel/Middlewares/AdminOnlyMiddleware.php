<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class AdminOnlyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $payload = $request->attribute('auth_payload');
        $usuario = $request->attribute('auth_user');

        // Detecta se é rota de página (não API) para redirecionar em vez de retornar JSON
        $isPage = !str_starts_with($request->getUri(), '/api/');

        if ($payload === null || $usuario === null) {
            return $isPage
                ? new Response('', 302, ['Location' => '/'])
                : Response::json(['error' => 'Autenticação obrigatória.'], 401);
        }

        $nivel = $payload->nivel_acesso ?? null;
        $nivelUsuario = method_exists($usuario, 'getNivelAcesso') ? $usuario->getNivelAcesso() : null;

        if ($nivel !== 'admin_system' || $nivelUsuario !== 'admin_system') {
            return $isPage
                ? new Response('', 302, ['Location' => '/'])
                : Response::json(['error' => 'Acesso restrito a admin_system.'], 403);
        }

        $result = $next($request);

        if ($result instanceof Response) {
            return $result;
        }

        // Garante retorno Response mesmo que o próximo handler devolva null/array/string
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        return Response::json([], 204);
    }
}
