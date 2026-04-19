<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Contracts\TokenPayloadInterface;

/**
 * Identidade para usuário não encontrado no banco.
 * Token válido, mas o subject não existe — resulta em 401.
 *
 * Distinto de InactiveAuthIdentity (usuário existe mas inativo → 403).
 * Sem estado: usa padrão singleton para evitar alocações desnecessárias.
 */
final class NotFoundAuthIdentity implements AuthIdentityInterface
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function id(): string|int|null           { return null; }
    public function role(): ?string                 { return null; }
    public function type(): string                  { return 'not_found'; }
    public function isAuthenticated(): bool         { return false; }
    public function isApiToken(): bool              { return false; }
    public function isGuest(): bool                 { return false; }
    public function hasRole(string ...$roles): bool { return false; }
    public function user(): mixed                   { return null; }
    public function payload(): ?TokenPayloadInterface { return null; }
}
