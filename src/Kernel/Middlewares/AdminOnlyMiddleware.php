<?php

declare(strict_types=1);

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\AuthorizationInterface;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Verifica permissão de admin após autenticação.
 * Auth ≠ Authorization: AuthContext diz quem é, AuthorizationInterface diz se pode.
 */
final class AdminOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?AuthContextInterface   $auth,
        private readonly ?AuthorizationInterface $authorization
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth === null || $this->authorization === null) {
            return $next($request);
        }

        $isPage   = !str_starts_with($request->getUri(), '/api/');
        $identity = $this->auth->identity($request);

        if ($identity === null) {
            return $isPage
                ? new Response('', 302, ['Location' => '/'])
                : Response::json(['error' => 'Autenticação obrigatória.'], 401);
        }

        if (!$this->authorization->isAdmin($identity, $request)) {
            return $isPage
                ? new Response('', 302, ['Location' => '/'])
                : Response::json(['error' => 'Acesso restrito.'], 403);
        }

        return $next($request);
    }
}
