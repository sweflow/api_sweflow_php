<?php

namespace Src\Kernel\Middlewares;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class AuthHybridMiddleware implements MiddlewareInterface
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
            return $this->responder(401, 'Não autenticado: token ausente.');
        }

        try {
            $payload = $this->decodificarJwtUsuario($token);
            $this->validarClaimsUsuario($payload);
        } catch (DomainException $e) {
            $this->limparCookieAuth();
            return $this->responder(401, $e->getMessage());
        } catch (\Throwable $e) {
            $this->limparCookieAuth();
            return $this->responder(401, 'Token inválido ou expirado.');
        }

        if ($this->blacklistRepo->isRevoked($payload->jti ?? '')) {
            return $this->responder(401, 'Token revogado. Faça login novamente.');
        }

        $usuario = $this->usuarios->buscarPorUuid($payload->sub);
        if (!$usuario) {
            return $this->responder(401, 'Usuário não encontrado.');
        }

        $request = $request
            ->withAttribute('auth_user', $usuario)
            ->withAttribute('auth_payload', $payload);

        return $next($request);
    }

    private function decodificarJwtUsuario(string $token): object
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        if ($secret === '') {
            throw new DomainException('JWT_SECRET não configurado.');
        }

        try {
            return JWT::decode($token, new Key($secret, 'HS256'));
        } catch (DomainException $e) {
            throw new DomainException('Token inválido: ' . $e->getMessage(), 401, $e);
        } catch (\Throwable $e) {
            throw new DomainException('Token inválido ou expirado.', 401, $e);
        }
    }

    private function validarClaimsUsuario(object $payload): void
    {
        if (!isset($payload->sub)) {
            throw new DomainException('Token sem identificador de usuário.', 401);
        }

        if (!isset($payload->tipo) || $payload->tipo !== 'user') {
            throw new DomainException('Token não é de usuário.', 401);
        }

        if (!isset($payload->jti) || trim((string)$payload->jti) === '') {
            throw new DomainException('Token sem jti.', 401);
        }

        $iss = $_ENV['JWT_ISSUER'] ?? getenv('JWT_ISSUER') ?? null;
        if ($iss && (!isset($payload->iss) || $payload->iss !== $iss)) {
            throw new DomainException('Emissor do token inválido.', 401);
        }

        $aud = $_ENV['JWT_AUDIENCE'] ?? getenv('JWT_AUDIENCE') ?? null;
        if ($aud && (!isset($payload->aud) || $payload->aud !== $aud)) {
            throw new DomainException('Audiência do token inválida.', 401);
        }
    }

    private function responder(int $status, string $mensagem): Response
    {
        return Response::json(['error' => $mensagem], $status);
    }

    private function limparCookieAuth(): void
    {
        if (isset($_COOKIE['auth_token'])) {
            @setcookie('auth_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
            ]);
        }
    }
}
