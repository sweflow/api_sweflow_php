<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\JwtDecoder;
use Src\Kernel\Support\TokenExtractor;

class OptionalAuthHybridMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepositoryInterface $usuarios,
        private TokenBlacklistInterface $blacklistRepo
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = TokenExtractor::fromRequest();
        if ($token === '') {
            return $next($request);
        }

        try {
            [$payload] = JwtDecoder::decodeUser($token);
            JwtDecoder::validateUserClaims($payload);

            if ($this->blacklistRepo->isRevoked($payload->jti ?? '')) {
                return $next($request);
            }

            $usuario = $this->usuarios->buscarPorUuid($payload->sub ?? '');
            if ($usuario) {
                $request = $request
                    ->withAttribute('auth_user', $usuario)
                    ->withAttribute('auth_payload', $payload);
            }
        } catch (\Throwable) {
            // Token inválido ou ausente — continua sem autenticação
        }

        return $next($request);
    }
}
