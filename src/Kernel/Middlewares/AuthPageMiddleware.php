<?php

declare(strict_types=1);

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\CookieConfig;

/**
 * Middleware de autenticação para rotas de página (HTML).
 *
 * Redireciona para / (ou /ide/login para rotas IDE) quando não autenticado,
 * em vez de retornar JSON 401.
 *
 * Recebe um AuthContextInterface já configurado com CookieTokenResolver.
 * O wiring correto é feito no container (index.php ou provider do módulo):
 *
 *   $container->bind(AuthPageMiddleware::class, fn($c) =>
 *       new AuthPageMiddleware(
 *           $c->make(AuthContextInterface::class)
 *               ->withResolver(new CookieTokenResolver())
 *       )
 *   );
 *
 * Verificação de admin é responsabilidade do AdminOnlyMiddleware — não deste.
 */
final class AuthPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?AuthContextInterface $auth
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth === null) {
            return $next($request);
        }

        // Debug: verifica se o cookie está presente
        error_log("DEBUG AuthPageMiddleware: URI=" . $request->getUri());
        error_log("DEBUG AuthPageMiddleware: Cookie presente? " . (isset($_COOKIE['auth_token']) ? 'SIM' : 'NÃO'));
        if (isset($_COOKIE['auth_token'])) {
            error_log("DEBUG AuthPageMiddleware: Cookie length=" . strlen($_COOKIE['auth_token']));
        }

        $identity = $this->auth->resolve($request);
        
        error_log("DEBUG AuthPageMiddleware: Identity type=" . ($identity ? $identity->type() : 'NULL'));

        if (!$this->isValid($identity)) {
            error_log("DEBUG AuthPageMiddleware: Identity inválida, redirecionando");
            return $this->redirecionar($identity !== null, $request->getUri());
        }

        error_log("DEBUG AuthPageMiddleware: Identity válida, prosseguindo");
        return $next(
            $request
                ->withAttribute(AuthContextInterface::IDENTITY_KEY, $identity)
                ->withAttribute(AuthContextInterface::LEGACY_USER_KEY, $identity->user())
                ->withAttribute(AuthContextInterface::LEGACY_PAYLOAD_KEY, $identity->payload())
        );
    }

    private function isValid(?AuthIdentityInterface $identity): bool
    {
        if ($identity === null) {
            return false;
        }
        $type = $identity->type();
        return $type !== 'inactive' && $type !== 'not_found';
    }

    private function redirecionar(bool $limparCookie, string $uri): Response
    {
        if ($limparCookie && isset($_COOKIE['auth_token'])) {
            setcookie('auth_token', '', CookieConfig::options(time() - 3600));
        }

        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        return new Response('', 302, [
            'Location' => str_starts_with($uri, '/dashboard/ide') ? '/ide/login' : '/',
        ]);
    }
}
