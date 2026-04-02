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
        $token = $this->extrairToken();

        if ($token === '') {
            return $this->redirecionar(false);
        }

        try {
            $payload = $this->decodificarToken($token);

            if (!isset($payload->tipo) || $payload->tipo !== 'user') {
                return $this->redirecionar(true);
            }

            if (empty($payload->jti)) {
                return $this->redirecionar(true);
            }

            if ($this->blacklistRepo->isRevoked($payload->jti)) {
                return $this->redirecionar(true);
            }

            $usuario = $this->usuarios->buscarPorUuid($payload->sub ?? '');
            if (!$usuario) {
                return $this->redirecionar(true);
            }

            // Dashboard é exclusivo para admin_system com token JWT_API_SECRET
            $nivelPayload  = $payload->nivel_acesso ?? null;
            $nivelUsuario  = method_exists($usuario, 'getNivelAcesso') ? $usuario->getNivelAcesso() : null;
            $tokenEhAdmin  = $this->tokenAssinadoComApiSecret($token);

            if ($nivelPayload !== 'admin_system' || $nivelUsuario !== 'admin_system' || !$tokenEhAdmin) {
                // Limpa cookie e redireciona — não permite acesso ao dashboard
                return $this->redirecionar(true);
            }

            $request = $request
                ->withAttribute('auth_user', $usuario)
                ->withAttribute('auth_payload', $payload);

            return $next($request);

        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->redirecionar(true);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            return $this->redirecionar(true);
        } catch (\Throwable $e) {
            return $this->redirecionar(false);
        }
    }

    private function extrairToken(): string
    {
        $cookieToken = $_COOKIE['auth_token'] ?? '';
        if (is_string($cookieToken) && trim($cookieToken) !== '') {
            return trim($cookieToken);
        }
        $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $bearer, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Tenta decodificar com JWT_API_SECRET primeiro (admin_system),
     * depois com JWT_SECRET (usuários comuns).
     */
    private function decodificarToken(string $token): object
    {
        $apiSecret = trim((string) ($_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? ''));
        if ($apiSecret !== '') {
            try {
                $payload = JWT::decode($token, new Key($apiSecret, 'HS256'));
                if (isset($payload->tipo) && $payload->tipo === 'user') {
                    return $payload;
                }
            } catch (\Throwable) {
                // não é token de admin — tenta JWT_SECRET
            }
        }

        $secret = trim((string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? ''));
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET não configurado.');
        }
        return JWT::decode($token, new Key($secret, 'HS256'));
    }

    private function tokenAssinadoComApiSecret(string $token): bool
    {
        $apiSecret = trim((string) ($_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? ''));
        if ($apiSecret === '') {
            return false;
        }
        try {
            JWT::decode($token, new Key($apiSecret, 'HS256'));
            return true;
        } catch (\Throwable) {
            return false;
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
