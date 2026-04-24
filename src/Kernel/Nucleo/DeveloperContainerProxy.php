<?php

declare(strict_types=1);

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Database\DeveloperConnectionResolver;

/**
 * Proxy de container para módulos do desenvolvedor (criados na IDE).
 *
 * Intercepta make(PDO::class) e retorna a conexão personalizada do desenvolvedor
 * (Aiven, etc.) em vez do banco core. A resolução é lazy — só acontece quando
 * o controller é instanciado durante o dispatch da request, garantindo que o
 * token JWT já esteja disponível.
 *
 * Para todos os outros bindings, delega ao container principal.
 *
 * Diferença do ModuleContainerProxy:
 *   - ModuleContainerProxy: recebe um PDO fixo (pdo.modules) no boot
 *   - DeveloperContainerProxy: resolve o PDO dinamicamente por request
 */
final class DeveloperContainerProxy implements ContainerInterface
{
    public function __construct(
        private readonly ContainerInterface $main
    ) {}

    public function bind(string $abstract, callable|object|string $concrete, bool $singleton = false): void
    {
        // Não permite que módulos do desenvolvedor sobrescrevam PDO::class
        if ($abstract === \PDO::class) {
            return;
        }
        $this->main->bind($abstract, $concrete, $singleton);
    }

    public function make(string $abstract): mixed
    {
        // PDO::class → retorna conexão personalizada do desenvolvedor
        if ($abstract === \PDO::class) {
            return DeveloperConnectionResolver::instance()->resolveOrDefault();
        }

        // Tudo mais → delega ao container principal com este proxy como contexto
        if ($this->main instanceof Container) {
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
