<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\JwtDecoder;
use Src\Kernel\Support\TokenExtractor;

class AuthHybridMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepositoryInterface $usuarios,
        private TokenBlacklistInterface $blacklistRepo
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = TokenExtractor::fromApiRequest();
        if ($token === '') {
            return $this->responder(401, 'Não autenticado.');
        }

        // Token de API puro (tipo: 'api') — acesso sem usuário
        if (JwtDecoder::isApiToken($token)) {
            return $next($request->withAttribute('api_token', true));
        }

        try {
            [$payload, $assinadoComApiSecret] = JwtDecoder::decodeUser($token);
            JwtDecoder::validateUserClaims($payload);
        } catch (\Throwable) {
            $this->limparCookieAuth();
            return $this->responder(401, 'Não autenticado.');
        }

        if ($this->blacklistRepo->isRevoked($payload->jti ?? '')) {
            $this->limparCookieAuth();
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

        // 403 mantém mensagem específica — é uma decisão de negócio, não de autenticação
        if (method_exists($usuario, 'isAtivo') && !$usuario->isAtivo()) {
            return $this->responder(403, 'Acesso negado.');
        }

        return $next(
            $request
                ->withAttribute('auth_user', $usuario)
                ->withAttribute('auth_payload', $payload)
                ->withAttribute('token_signed_with_api_secret', $assinadoComApiSecret)
        );
    }

    private function responder(int $status, string $mensagem): Response
    {
        return Response::json(['error' => $mensagem], $status);
    }

    private function limparCookieAuth(): void
    {
        if (isset($_COOKIE['auth_token']) && !headers_sent()) {
            setcookie('auth_token', '', \Src\Kernel\Support\CookieConfig::options(time() - 3600));
        }
    }
}
