<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenResolverInterface;
use Src\Kernel\Http\Request\Request;

/**
 * Resolve token tentando múltiplas fontes em ordem de prioridade.
 *
 * Evita duplicar middleware só para trocar a fonte do token.
 * Cada resolver tenta extrair o token — o primeiro que retornar
 * string não-vazia vence.
 *
 * Uso:
 *   $resolver = new CompositeTokenResolver([
 *       new BearerTokenResolver(),   // Authorization: Bearer
 *       new CookieTokenResolver(),   // Cookie auth_token
 *       new ApiKeyHeaderResolver(),  // X-API-KEY
 *   ]);
 *
 * Módulos podem registrar seu próprio resolver composto:
 *   $container->bind(TokenResolverInterface::class, fn() => new CompositeTokenResolver([...]), true);
 */
final class CompositeTokenResolver implements TokenResolverInterface
{
    /** @param TokenResolverInterface[] $resolvers */
    public function __construct(private readonly array $resolvers) {}

    public function resolve(Request $request): string
    {
        foreach ($this->resolvers as $resolver) {
            $token = $resolver->resolve($request);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }
}
