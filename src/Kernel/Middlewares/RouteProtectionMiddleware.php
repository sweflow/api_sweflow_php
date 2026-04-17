<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\JwtDecoder;
use Src\Kernel\Support\TokenExtractor;

/**
 * Protege rotas que aceitam tanto token de usuário quanto token de API.
 * Sempre valida a assinatura JWT antes de inspecionar o payload.
 */
class RouteProtectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?TokenBlacklistInterface $blacklistRepo = null
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $roles = $request->attribute('roles', []);

        $token = TokenExtractor::fromApiRequest();
        if ($token === '') {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        // Token de API puro (tipo: 'api') — acesso sem usuário
        if (JwtDecoder::isApiToken($token)) {
            if (!empty($roles)) {
                return Response::json(['error' => 'Token de API não suporta restrição por papel.'], 403);
            }
            return $next($request);
        }

        // Token de usuário
        try {
            [$payload] = JwtDecoder::decodeUser($token);
            JwtDecoder::validateUserClaims($payload);
        } catch (\Throwable) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        // Verifica blacklist se disponível
        if ($this->blacklistRepo !== null && $this->blacklistRepo->isRevoked($payload->jti ?? '')) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        if (!empty($roles)) {
            $userRole = $payload->nivel_acesso ?? null;
            if (!in_array($userRole, $roles, true)) {
                return Response::json(['error' => 'Acesso restrito para este papel.'], 403);
            }
        }

        return $next($request);
    }
}
