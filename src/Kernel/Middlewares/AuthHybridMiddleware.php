<?php

declare(strict_types=1);

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

final class AuthHybridMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?AuthContextInterface $auth) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth === null) {
            return $next($request);
        }

        $identity = $this->auth->resolve($request);

        if ($identity === null || $identity->type() === 'not_found') {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        if ($identity->type() === 'inactive') {
            return Response::json(['error' => 'Acesso negado.'], 403);
        }

        return $next(
            $request
                ->withAttribute(AuthContextInterface::IDENTITY_KEY, $identity)
                ->withAttribute(AuthContextInterface::LEGACY_USER_KEY, $identity->user())
                ->withAttribute(AuthContextInterface::LEGACY_PAYLOAD_KEY, $identity->payload())
        );
    }
}
