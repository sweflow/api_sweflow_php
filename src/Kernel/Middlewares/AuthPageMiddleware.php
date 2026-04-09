<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\JwtDecoder;
use Src\Kernel\Support\TokenExtractor;

/**
 * Middleware de autenticação para rotas de página (HTML).
 * Redireciona para / quando não autenticado, em vez de retornar JSON 401.
 * Exclusivo para admin_system com token assinado via JWT_API_SECRET.
 */
class AuthPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepositoryInterface $usuarios,
        private TokenBlacklistInterface $blacklistRepo
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = TokenExtractor::fromRequest();
        if ($token === '') {
            return $this->redirecionar(false);
        }

        try {
            [$payload, $assinadoComApiSecret] = JwtDecoder::decodeUser($token);

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

            // Dashboard exclusivo para admin_system com token JWT_API_SECRET
            $nivelPayload = $payload->nivel_acesso ?? null;
            $nivelUsuario = method_exists($usuario, 'getNivelAcesso') ? $usuario->getNivelAcesso() : null;

            if ($nivelPayload !== 'admin_system' || $nivelUsuario !== 'admin_system' || !$assinadoComApiSecret) {
                return $this->redirecionar(true);
            }

            return $next(
                $request
                    ->withAttribute('auth_user', $usuario)
                    ->withAttribute('auth_payload', $payload)
                    ->withAttribute('token_signed_with_api_secret', $assinadoComApiSecret)
            );

        } catch (\Firebase\JWT\ExpiredException|\Firebase\JWT\SignatureInvalidException) {
            return $this->redirecionar(true);
        } catch (\Throwable) {
            return $this->redirecionar(false);
        }
    }

    private function redirecionar(bool $limparCookie): Response
    {
        if ($limparCookie && isset($_COOKIE['auth_token'])) {
            setcookie('auth_token', '', \Src\Kernel\Support\CookieConfig::options(time() - 3600));
        }

        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }
        return new Response('', 302, ['Location' => '/']);
    }
}
