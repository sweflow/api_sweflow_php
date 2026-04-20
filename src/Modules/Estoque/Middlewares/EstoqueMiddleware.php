<?php

namespace Src\Modules\Estoque\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

final class EstoqueMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Obtém a identidade do usuário autenticado (injetada pelo AuthHybridMiddleware)
        $identity = $request->attribute(AuthContextInterface::IDENTITY_KEY);

        if ($identity === null || !($identity instanceof AuthIdentityInterface)) {
            return Response::json(['error' => 'Autenticação obrigatória.'], 401);
        }

        if ($identity->isGuest()) {
            return Response::json(['error' => 'Autenticação obrigatória.'], 401);
        }

        // Verifica se o usuário tem permissão para acessar o módulo de estoque
        // Permite: admin_system ou usuários com nível 'usuario'
        if (!$identity->hasRole('admin_system', 'usuario')) {
            return Response::json(['error' => 'Acesso negado ao módulo de estoque.'], 403);
        }

        $this->log($identity->id(), $request);

        return $next($request);
    }

    private function log(string|int|null $userId, Request $request): void
    {
        error_log(sprintf(
            '[ESTOQUE] user=%s method=%s uri=%s',
            $userId ?? 'guest',
            $request->method,
            $request->getUri()
        ));
    }
}
