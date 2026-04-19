<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Contracts\AuthorizationInterface;
use Src\Kernel\Contracts\TokenPayloadInterface;

/**
 * Implementação padrão de AuthIdentityInterface.
 *
 * Imutável: construtor privado, readonly properties, payload já tipado.
 * hasRole() delega para AuthorizationInterface quando disponível.
 */
final class AuthIdentity implements AuthIdentityInterface
{
    private function __construct(
        private readonly mixed                    $user,
        private readonly ?TokenPayloadInterface   $payload,
        private readonly bool                     $authenticated,
        private readonly bool                     $apiToken,
        private readonly ?AuthorizationInterface  $authorization
    ) {}

    public static function forUser(
        mixed $user,
        TokenPayloadInterface $payload,
        ?AuthorizationInterface $authorization = null
    ): self {
        return new self($user, $payload, true, false, $authorization);
    }

    public static function forApiToken(): self
    {
        return new self(null, null, false, true, null);
    }

    // ── Identificação ─────────────────────────────────────────────────

    public function id(): string|int|null
    {
        return $this->payload?->getSubject();
    }

    public function role(): ?string
    {
        if ($this->apiToken || $this->user === null) {
            return null;
        }

        // AuthenticatableInterface — contrato padrão do kernel
        if ($this->user instanceof \Src\Kernel\Contracts\AuthenticatableInterface) {
            return $this->user->getAuthRole();
        }

        // Convenção do módulo Usuario nativo
        if (method_exists($this->user, 'getNivelAcesso')) {
            return $this->user->getNivelAcesso();
        }

        return $this->payload?->getRole();
    }

    public function type(): string
    {
        return match(true) {
            $this->apiToken      => 'api_token',
            $this->authenticated => 'user',
            default              => 'guest',
        };
    }

    // ── Verificações de estado ────────────────────────────────────────

    public function isAuthenticated(): bool { return $this->authenticated; }
    public function isApiToken(): bool      { return $this->apiToken; }
    public function isGuest(): bool         { return !$this->authenticated && !$this->apiToken; }

    public function hasRole(string ...$roles): bool
    {
        if ($this->apiToken || $this->user === null) {
            return false;
        }

        if ($this->authorization !== null) {
            return $this->authorization->hasRole($this, ...$roles);
        }

        $role = $this->role();
        return $role !== null && in_array($role, $roles, true);
    }

    // ── Escape hatches ────────────────────────────────────────────────

    public function user(): mixed                    { return $this->user; }
    public function payload(): ?TokenPayloadInterface { return $this->payload; }
}
