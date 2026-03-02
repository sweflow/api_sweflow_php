<?php

namespace Src\Middlewares;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Contracts\MiddlewareInterface;
use Src\Http\Request\Request;
use Src\Http\Response\Response;
use Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface;

class AuthCookieMiddleware implements MiddlewareInterface
{
    public function __construct(private UsuarioRepositoryInterface $usuarios)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $_COOKIE['auth_token'] ?? '';
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            return $this->responder(401, 'Não autenticado: cookie ausente.');
        }

        $payload = $this->decodificarJwt($token);
        $this->validarClaimsUsuario($payload);

        $usuario = $this->usuarios->buscarPorUuid($payload->sub);
        if (!$usuario) {
            return $this->responder(401, 'Usuário não encontrado.');
        }

        $request = $request
            ->withAttribute('auth_user', $usuario)
            ->withAttribute('auth_payload', $payload);

        return $next($request);
    }

    private function decodificarJwt(string $token): object
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
}
