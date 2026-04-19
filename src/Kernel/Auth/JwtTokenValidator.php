<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenBlacklistInterface;
use Src\Kernel\Contracts\TokenPayloadInterface;
use Src\Kernel\Contracts\TokenValidatorInterface;
use Src\Kernel\Support\JwtDecoder;

/**
 * Valida tokens JWT e retorna JwtPayload tipado.
 * Verifica assinatura, claims, expiração e blacklist.
 */
final class JwtTokenValidator implements TokenValidatorInterface
{
    public function __construct(
        private readonly TokenBlacklistInterface $blacklist
    ) {}

    public function validate(string $token): ?TokenPayloadInterface
    {
        if ($token === '' || $this->isApiToken($token)) {
            return null;
        }

        try {
            [$rawPayload, $assinadoComApiSecret] = JwtDecoder::decodeUser($token);
            JwtDecoder::validateUserClaims($rawPayload);
        } catch (\Throwable) {
            $this->limparCookieAuth();
            return null;
        }

        if ($this->blacklist->isRevoked($rawPayload->jti ?? '')) {
            $this->limparCookieAuth();
            return null;
        }

        return new JwtPayload($rawPayload, $assinadoComApiSecret);
    }

    public function isApiToken(string $token): bool
    {
        return JwtDecoder::isApiToken($token);
    }

    private function limparCookieAuth(): void
    {
        if (isset($_COOKIE['auth_token']) && !headers_sent()) {
            setcookie('auth_token', '', \Src\Kernel\Support\CookieConfig::options(time() - 3600));
        }
    }
}
