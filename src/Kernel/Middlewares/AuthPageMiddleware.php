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

        // Sem token → redireciona para home
        if ($token === '') {
            return $this->redirecionar(false);
        }

        try {
            $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
            if ($secret === '') {
                return $this->redirecionar(false);
            }

            $payload = JWT::decode($token, new Key($secret, 'HS256'));

            // Valida tipo
            if (!isset($payload->tipo) || $payload->tipo !== 'user') {
                return $this->redirecionar(true); // token inválido — limpa cookie
            }

            // Valida jti
            if (empty($payload->jti)) {
                return $this->redirecionar(true);
            }

            // Verifica blacklist
            if ($this->blacklistRepo->isRevoked($payload->jti)) {
                return $this->redirecionar(true);
            }

            // Busca usuário — se o banco estiver fora do ar, não apaga o cookie
            $usuario = $this->usuarios->buscarPorUuid($payload->sub ?? '');
            if (!$usuario) {
                return $this->redirecionar(true);
            }

            $request = $request
                ->withAttribute('auth_user', $usuario)
                ->withAttribute('auth_payload', $payload);

            return $next($request);

        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->redirecionar(true); // token expirado — limpa cookie
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            return $this->redirecionar(true); // assinatura inválida — limpa cookie
        } catch (\Throwable $e) {
            // Erro de banco ou outro — não apaga o cookie, apenas redireciona
            return $this->redirecionar(false);
        }
    }

    private function redirecionar(bool $limparCookie): Response
    {
        if ($limparCookie && isset($_COOKIE['auth_token'])) {
            $appUrl  = $_ENV['APP_URL'] ?? '';
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || strncmp($appUrl, 'https://', 8) === 0;

            @setcookie('auth_token', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isHttps,
                'samesite' => 'Lax',
            ]);
        }

        // Clientes JSON recebem 401
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }
        // Browsers recebem redirect para a home
        return new Response('', 302, ['Location' => '/']);
    }
}
