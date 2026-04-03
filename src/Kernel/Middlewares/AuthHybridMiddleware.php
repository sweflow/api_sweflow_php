<?php

namespace Src\Kernel\Middlewares;

use DomainException;
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
            return $this->responder(401, 'Não autenticado: token ausente.');
        }

        // Token de API puro (tipo: 'api') — acesso sem usuário
        if (JwtDecoder::isApiToken($token)) {
            return $next($request->withAttribute('api_token', true));
        }

        try {
            [$payload, $assinadoComApiSecret] = JwtDecoder::decodeUser($token);
            JwtDecoder::validateUserClaims($payload);
        } catch (DomainException $e) {
            $this->limparCookieAuth();
            return $this->responder(401, $e->getMessage());
        } catch (\Throwable) {
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
        if (isset($_COOKIE['auth_token'])) {
            @setcookie('auth_token', '', \Src\Kernel\Support\CookieConfig::options(time() - 3600));
        }
    }
}
