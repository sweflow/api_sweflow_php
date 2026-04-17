<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\JwtDecoder;

class AuthCookieMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?UserRepositoryInterface $usuarios,
        private ?TokenBlacklistInterface $blacklistRepo
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->usuarios === null || $this->blacklistRepo === null) {
            return $next($request);
        }

        $token = $_COOKIE['auth_token'] ?? '';
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            return $this->responder(401, 'Não autenticado.');
        }

        try {
            [$payload] = JwtDecoder::decodeUser($token);
            JwtDecoder::validateUserClaims($payload);
        } catch (\Throwable) {
            return $this->responder(401, 'Não autenticado.');
        }

        if ($this->blacklistRepo->isRevoked($payload->jti ?? '')) {
            return $this->responder(401, 'Não autenticado.');
        }

        $sub = $payload->sub ?? '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sub)) {
            return $this->responder(401, 'Não autenticado.');
        }

        $usuario = $this->usuarios->buscarPorUuid($sub);
        if (!$usuario) {
            return $this->responder(401, 'Não autenticado.');
        }

        if (method_exists($usuario, 'isAtivo') && !$usuario->isAtivo()) {
            return $this->responder(403, 'Acesso negado.');
        }

        return $next(
            $request
                ->withAttribute('auth_user', $usuario)
                ->withAttribute('auth_payload', $payload)
        );
    }

    private function responder(int $status, string $mensagem): Response
    {
        return Response::json(['error' => $mensagem], $status);
    }
}
