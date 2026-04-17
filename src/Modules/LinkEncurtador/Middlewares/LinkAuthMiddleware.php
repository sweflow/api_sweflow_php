<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\LinkEncurtador\Controllers\AuthController;

/**
 * Middleware de autenticação exclusivo do encurtador de links.
 * Usa a tabela link_usuarios — completamente separado do Auth do kernel.
 */
final class LinkAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthController $authController,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $user = $this->authController->resolveUser($request);
        if ($user === null) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }
        return $next($request->withAttribute('link_user', $user));
    }
}
