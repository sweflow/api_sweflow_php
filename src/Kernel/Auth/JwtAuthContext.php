<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Contracts\AuthorizationInterface;
use Src\Kernel\Contracts\IdentityFactoryInterface;
use Src\Kernel\Contracts\TokenPayloadInterface;
use Src\Kernel\Contracts\TokenResolverInterface;
use Src\Kernel\Contracts\TokenValidatorInterface;
use Src\Kernel\Contracts\UserResolverInterface;
use Src\Kernel\Http\Request\Request;

/**
 * Orquestrador de autenticação JWT.
 *
 * Pipeline:
 *   TokenResolverInterface   → extrai o token do Request
 *   TokenValidatorInterface  → valida e retorna TokenPayloadInterface
 *   UserResolverInterface    → resolve o usuário pelo subject do payload
 *   IdentityFactoryInterface → monta AuthIdentityInterface
 *
 * Lazy user resolution: o UserResolver só é chamado quando necessário.
 * Rotas que só precisam do payload (ex: verificar tipo de token) não
 * fazem query ao banco.
 */
final class JwtAuthContext implements AuthContextInterface, AuthorizationInterface
{
    // Constants are defined on AuthContextInterface:
    //   IDENTITY_KEY, LEGACY_USER_KEY, LEGACY_PAYLOAD_KEY

    public function __construct(
        private readonly TokenResolverInterface   $tokenResolver,
        private readonly TokenValidatorInterface  $tokenValidator,
        private readonly UserResolverInterface    $userResolver,
        private readonly IdentityFactoryInterface $identityFactory
    ) {}

    // ── AuthContextInterface ──────────────────────────────────────────

    public function resolve(Request $request): ?AuthIdentityInterface
    {
        $token = $this->tokenResolver->resolve($request);
        if ($token === '') {
            return null;
        }

        if ($this->tokenValidator->isApiToken($token)) {
            return $this->identityFactory->forApiToken();
        }

        $payload = $this->tokenValidator->validate($token);
        if ($payload === null) {
            return null;
        }

        $identifier = $payload->getSubject();
        if ($identifier === null || $identifier === '') {
            return null;
        }

        // Ponto 4: lazy resolution — só busca o usuário aqui,
        // após todas as validações de token passarem.
        // Rotas que rejeitam antes (rate limit, bot blocker) nunca chegam aqui.
        $user = $this->userResolver->resolve((string) $identifier, $payload);

        return $this->identityFactory->forUser($user, $payload);
    }

    public function identity(Request $request): ?AuthIdentityInterface
    {
        $identity = $request->attribute(self::IDENTITY_KEY);
        return $identity instanceof AuthIdentityInterface ? $identity : null;
    }

    // ── AuthorizationInterface ────────────────────────────────────────

    public function isAdmin(AuthIdentityInterface $identity, Request $request): bool
    {
        if ($identity->isApiToken()) {
            return true;
        }

        $payload = $identity->payload();
        if ($payload === null) {
            return false;
        }

        return $identity->hasRole('admin_system')
            && $payload->isSignedWithApiSecret();
    }

    public function hasRole(AuthIdentityInterface $identity, string ...$roles): bool
    {
        if (!$identity->isAuthenticated()) {
            return false;
        }

        $role = $identity->role();
        return $role !== null && in_array($role, $roles, true);
    }

    // ── Fluent builder ────────────────────────────────────────────────

    public function withResolver(TokenResolverInterface $resolver): self
    {
        return new self(
            $resolver,
            $this->tokenValidator,
            $this->userResolver,
            $this->identityFactory
        );
    }
}
