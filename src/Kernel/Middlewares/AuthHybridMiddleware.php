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

        // Aceita também X-API-KEY para tokens de API
        $apiKeyHeader = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));

        $token = $cookieToken !== '' ? $cookieToken : ($bearerToken !== '' ? $bearerToken : $apiKeyHeader);
        if ($token === '') {
            return $this->responder(401, 'Não autenticado: token ausente.');
        }

        // Verifica se é token de API (JWT_API_SECRET com tipo:api)
        if ($this->isApiToken($token)) {
            $request = $request->withAttribute('api_token', true);
            return $next($request);
        }

        try {
            [$payload, $assinadoComApiSecret] = $this->decodificarJwtUsuario($token);
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

        $sub = $payload->sub ?? '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sub)) {
            return $this->responder(401, 'Token inválido: identificador de usuário malformado.');
        }

        $usuario = $this->usuarios->buscarPorUuid($sub);
        if (!$usuario) {
            return $this->responder(401, 'Usuário não encontrado.');
        }

        $request = $request
            ->withAttribute('auth_user', $usuario)
            ->withAttribute('auth_payload', $payload)
            ->withAttribute('token_signed_with_api_secret', $assinadoComApiSecret);

        return $next($request);
    }

    /**
     * Verifica se o token é um token de API válido (JWT_API_SECRET, tipo:api).
     */
    private function isApiToken(string $token): bool
    {
        $secret = trim((string) ($_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? ''));
        if ($secret === '') {
            return false;
        }
        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
            return !empty($payload->api_access) || ($payload->tipo ?? '') === 'api';
        } catch (\Throwable) {
            return false;
        }
    }

    private function decodificarJwtUsuario(string $token): array
    {
        // Tenta JWT_API_SECRET primeiro (tokens de admin_system via /api/auth/login)
        $apiSecret = trim((string) ($_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? ''));
        if ($apiSecret !== '') {
            try {
                $payload = JWT::decode($token, new Key($apiSecret, 'HS256'));
                if (isset($payload->tipo) && $payload->tipo === 'user') {
                    return [$payload, true]; // [payload, assinadoComApiSecret]
                }
            } catch (\Throwable) {
                // não é um token de admin — tenta JWT_SECRET
            }
        }

        // Tenta JWT_SECRET (tokens de usuários comuns via /api/login)
        $secret = trim((string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? ''));
        if ($secret === '') {
            throw new DomainException('JWT_SECRET não configurado.');
        }

        try {
            return [JWT::decode($token, new Key($secret, 'HS256')), false];
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

        // Valida iss: se JWT_ISSUER estiver configurado, o token DEVE ter iss e ele DEVE bater
        $iss = $_ENV['JWT_ISSUER'] ?? getenv('JWT_ISSUER') ?? null;
        if ($iss) {
            if (!isset($payload->iss) || $payload->iss !== $iss) {
                throw new DomainException('Emissor do token inválido.', 401);
            }
        }

        // Valida aud: se JWT_AUDIENCE estiver configurado, o token DEVE ter aud e ele DEVE bater
        $aud = $_ENV['JWT_AUDIENCE'] ?? getenv('JWT_AUDIENCE') ?? null;
        if ($aud) {
            $tokenAud = $payload->aud ?? null;
            $audMatch = is_array($tokenAud) ? in_array($aud, $tokenAud, true) : $tokenAud === $aud;
            if (!$audMatch) {
                throw new DomainException('Audiência do token inválida.', 401);
            }
        }
    }

    private function responder(int $status, string $mensagem): Response
    {
        return Response::json(['error' => $mensagem], $status);
    }

    private function limparCookieAuth(): void
    {
        if (isset($_COOKIE['auth_token'])) {
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
    }
}
