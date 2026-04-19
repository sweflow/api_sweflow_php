<?php

declare(strict_types=1);

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Variante do AuthHybridMiddleware que aceita apenas cookie.
 *
 * Recebe um AuthContextInterface já configurado com CookieTokenResolver.
 * O wiring correto é feito no container (index.php ou provider do módulo):
 *
 *   $container->bind(AuthCookieMiddleware::class, fn($c) =>
 *       new AuthCookieMiddleware(
 *           $c->make(AuthContextInterface::class)
 *               ->withResolver(new CookieTokenResolver())
 *       )
 *   );
 *
 * Se o AuthContextInterface não suportar withResolver(), use um
 * CompositeTokenResolver que inclua CookieTokenResolver como primeira fonte.
 */
final class AuthCookieMiddleware implements MiddlewareInterface
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
