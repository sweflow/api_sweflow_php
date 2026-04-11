<?php

namespace Src\Modules\Tarefa\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Middleware do modulo Tarefa.
 *
 * Para usar nas rotas:
 *   $router->get('/api/...', [Controller::class, 'method'], [TarefaMiddleware::class]);
 */
class TarefaMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Logica antes da requisicao
        // Exemplo: validar permissoes, logging, etc.

        $response = $next($request);

        // Logica depois da requisicao

        return $response;
    }
}
