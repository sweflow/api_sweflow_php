<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Middleware de autenticação para rotas de página (HTML).
 * Redireciona para / quando não autenticado, em vez de retornar JSON 401.
 * Também aceita token via cookie OU via Authorization Bearer.
 */
class AuthPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepositoryInterface $usuarios,
        private TokenBlacklistInterface $blacklistRepo
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // Tenta cookie primeiro
        $token = '';
        $cookieToken = $_COOKIE['auth_token'] ?? '';
        if (is_string($cookieToken) && trim($cookieToken) !== '') {
            $token = trim($cookieToken);
        }

        // Fallback: Authorization Bearer
        if ($token === '') {
            $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.+)/i', $bearer, $m)) {
                $token = trim($m[1]);
            }
        }

        // Sem token → redireciona para home com flag de login
        if ($token === '') {
            return $this->redirecionar();
        }

        try {
            $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
            if ($secret === '') {
                return $this->redirecionar();
            }

            $payload = JWT::decode($token, new Key($secret, 'HS256'));

            // Valida tipo
            if (!isset($payload->tipo) || $payload->tipo !== 'user') {
                return $this->redirecionar();
            }

            // Valida jti
            if (empty($payload->jti)) {
                return $this->redirecionar();
            }

            // Verifica blacklist
            if ($this->blacklistRepo->isRevoked($payload->jti)) {
                return $this->redirecionar();
            }

            // Busca usuário
            $usuario = $this->usuarios->buscarPorUuid($payload->sub ?? '');
            if (!$usuario) {
                return $this->redirecionar();
            }

            $request = $request
                ->withAttribute('auth_user', $usuario)
                ->withAttribute('auth_payload', $payload);

            return $next($request);

        } catch (\Throwable) {
            return $this->redirecionar();
        }
    }

    private function redirecionar(): Response
    {
        // Redireciona para a home — o JS abre o modal de login automaticamente
        return new Response('', 302, ['Location' => '/']);
    }
}
