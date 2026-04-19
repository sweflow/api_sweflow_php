<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Contracts\AuthorizationInterface;
use Src\Kernel\Contracts\IdentityFactoryInterface;
use Src\Kernel\Contracts\TokenPayloadInterface;

/**
 * Cria AuthIdentity padrão.
 *
 * Centraliza a decisão de inatividade — o UserResolver só retorna o usuário,
 * a factory decide qual tipo de identidade criar.
 */
final class DefaultIdentityFactory implements IdentityFactoryInterface
{
    private ?AuthorizationInterface $resolvedAuthorization = null;
    private bool $resolved = false;

    public function __construct(
        private readonly AuthorizationInterface|\Closure|null $authorization = null
    ) {}

    /**
     * Resolve o AuthorizationInterface sob demanda (lazy).
     * Quebra dependência circular no container.
     */
    private function getAuthorization(): ?AuthorizationInterface
    {
        if ($this->resolved) {
            return $this->resolvedAuthorization;
        }

        $this->resolved = true;

        if ($this->authorization instanceof \Closure) {
            $this->resolvedAuthorization = ($this->authorization)();
        } elseif ($this->authorization instanceof AuthorizationInterface) {
            $this->resolvedAuthorization = $this->authorization;
        } else {
            $this->resolvedAuthorization = null;
        }

        return $this->resolvedAuthorization;
    }

    public function forUser(mixed $user, TokenPayloadInterface $payload): AuthIdentityInterface
    {
        // Token válido mas usuário não existe no banco → 401 (não autenticado)
        if ($user === null) {
            return NotFoundAuthIdentity::instance();
        }

        // Usuário existe mas está inativo → 403 (acesso negado)
        if (method_exists($user, 'isAtivo') && !$user->isAtivo()) {
            return InactiveAuthIdentity::instance();
        }

        return AuthIdentity::forUser($user, $payload, $this->getAuthorization());
    }

    public function forApiToken(): AuthIdentityInterface
    {
        return AuthIdentity::forApiToken();
    }
}
