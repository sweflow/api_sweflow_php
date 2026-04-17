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
 */
final class ModuleContainerProxy implements ContainerInterface
{
    public function __construct(
        private readonly ContainerInterface $main,
        private readonly \PDO $modulesPdo
    ) {}

    public function bind(string $abstract, callable|object|string $concrete, bool $singleton = false): void
    {
        // Não sobrescreve bindings já existentes no container principal.
        // Isso evita que módulos externos sobrescrevam UserRepositoryInterface,
        // PDO::class ou outros contratos do sistema core.
        if ($this->main instanceof \Src\Kernel\Nucleo\Container
            && $this->main->hasBinding($abstract)) {
            return;
        }
        $this->main->bind($abstract, $concrete, $singleton);
    }

    public function make(string $abstract): mixed
    {
        // PDO::class → retorna pdo.modules
        if ($abstract === \PDO::class) {
            return $this->modulesPdo;
        }
        // Tudo mais → delega ao container principal
        // mas injeta este proxy como contexto para que closures recebam o proxy como $c
        if ($this->main instanceof \Src\Kernel\Nucleo\Container) {
            $this->main->setResolveContext($this);
            try {
                return $this->main->make($abstract);
            } finally {
                $this->main->setResolveContext(null);
            }
        }
        return $this->main->make($abstract);
    }
}
