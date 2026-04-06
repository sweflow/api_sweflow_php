<?php

namespace Src\Kernel\Nucleo;

use Src\Kernel\Nucleo\ModuleLoader;

class LeitorModulos
{
    public function __construct(
        private ModuleLoader $modules
    ) {}

    public function ler(): array
    {
        $modulos = [];
        foreach ($this->modules->providers() as $nome => $provider) {
            if (!$this->modules->isEnabled($nome)) {
                continue;
            }
            $descricao = $provider->describe();
            $modulos[] = [
                'nome'  => $nome,
                'rotas' => $descricao['routes'] ?? [],
            ];
        }
        return $modulos;
    }

    public function lerCompleto(): array
    {
        $modulos = [];
        foreach ($this->modules->providers() as $nome => $provider) {
            $desc    = $provider->describe();
            $modulos[] = [
                'name'        => $nome,
                'enabled'     => $this->modules->isEnabled($nome),
                'protected'   => $this->modules->isProtected($nome),
                'description' => $desc['description'] ?? '',
                'version'     => $desc['version'] ?? '1.0.0',
                'routes'      => $desc['routes'] ?? [],
            ];
        }
        return $modulos;
    }

    public function alternar(string $modulo): bool
    {
        return $this->modules->toggle($modulo);
    }
}
