<?php

declare(strict_types=1);

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;

/**
 * Proxy de container para módulos que usam DB2.
 *
 * - make(PDO::class) → retorna pdo.modules
 * - bind(...) → propaga para o container principal (bindings ficam globais)
 * - make(qualquer outra coisa) → delega ao container principal
 *
 * Isso permite que o AccountsServiceProvider::boot() faça:
 *   $container->bind(UserRepositoryInterface::class, fn($c) => new AccountRepository($c->make(PDO::class)))
 *
 * O bind de UserRepositoryInterface vai para o container principal,
 * e quando resolvido, $c->make(PDO::class) retorna pdo.modules via este proxy.
 *
 * Contratos de autenticação (AuthContextInterface, UserRepositoryInterface,
 * TokenBlacklistInterface) podem ser sobrescritos por módulos externos — são
 * os pontos de extensão intencionais da plataforma.
 */
final class ModuleContainerProxy implements ContainerInterface
{
    /** Contratos que módulos externos podem sobrescrever intencionalmente */
    private const OVERRIDABLE = [
        \Src\Kernel\Contracts\AuthContextInterface::class,
        \Src\Kernel\Contracts\AuthorizationInterface::class,
        \Src\Kernel\Contracts\TokenResolverInterface::class,
        \Src\Kernel\Contracts\TokenValidatorInterface::class,
        \Src\Kernel\Contracts\UserResolverInterface::class,
        \Src\Kernel\Contracts\IdentityFactoryInterface::class,
        \Src\Kernel\Contracts\UserRepositoryInterface::class,
        \Src\Kernel\Contracts\TokenBlacklistInterface::class,
    ];
    public function __construct(
        private readonly ContainerInterface $main,
        private readonly \PDO $modulesPdo
    ) {}

    public function bind(string $abstract, callable|object|string $concrete, bool $singleton = false): void
    {
        // Contratos de autenticação são pontos de extensão intencionais —
        // módulos externos podem sobrescrevê-los livremente.
        if (in_array($abstract, self::OVERRIDABLE, true)) {
            $this->main->bind($abstract, $concrete, $singleton);
            return;
        }

        // Não sobrescreve bindings já existentes no container principal.
        // Isso evita que módulos externos sobrescrevam PDO::class ou outros
        // contratos do sistema core.
        if ($this->main instanceof \Src\Kernel\Nucleo\Container
            && $this->main->hasBinding($abstract)) {
            return;
        }
        $this->main->bind($abstract, $concrete, $singleton);
    }

    public function make(string $abstract): mixed
    {
        // PDO::class → retorna pdo.modules diretamente
        if ($abstract === \PDO::class) {
            return $this->modulesPdo;
        }
        // Tudo mais → delega ao container principal com este proxy como contexto
        // para que closures recebam o proxy como $c (e resolvam PDO::class corretamente)
        if ($this->main instanceof \Src\Kernel\Nucleo\Container) {
            $prev = $this->main->getResolveContext();
            if ($prev !== $this) {
                $this->main->setResolveContext($this);
                try {
                    return $this->main->make($abstract);
                } finally {
                    $this->main->setResolveContext($prev);
                }
            }
        }
        return $this->main->make($abstract);
    }
}
