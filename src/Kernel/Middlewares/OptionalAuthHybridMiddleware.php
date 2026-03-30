<?php

namespace Src\Kernel\Middlewares;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class OptionalAuthHybridMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepositoryInterface $usuarios,
        private TokenBlacklistInterface $blacklistRepo
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $cookieToken = $_COOKIE['auth_token'] ?? '';
        $cookieToken = is_string($cookieToken) ? trim($cookieToken) : '';

        $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $bearerToken = '';
        if (preg_match('/Bearer\s+(.*)/i', $bearer, $m)) {
            $bearerToken = trim($m[1]);
        }

        $token = $cookieToken !== '' ? $cookieToken : $bearerToken;
        if ($token === '') {
            return $next($request);
        }

        try {
            $payload = $this->decodificarJwtUsuario($token);
            if ($this->blacklistRepo->isRevoked($payload->jti ?? '')) {
                return $next($request);
            }
            $usuario = $this->usuarios->buscarPorUuid($payload->sub ?? '');
            if (!$usuario) {
                return $next($request);
            }

            $request = $request
                ->withAttribute('auth_user', $usuario)
                ->withAttribute('auth_payload', $payload);
        } catch (\Throwable $e) {
            return $next($request);
        }

        return $next($request);
    }

    private function decodificarJwtUsuario(string $token): object
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET não configurado.');
        }
        return JWT::decode($token, new Key($secret, 'HS256'));
    }
}
