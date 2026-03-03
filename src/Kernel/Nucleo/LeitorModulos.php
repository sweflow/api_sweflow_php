<?php
namespace Src\Nucleo;

class LeitorModulos
{
    public function __construct(private ModuleLoader $modules)
    {
    }

    public function ler(): array
    {
        $modulos = [];
        foreach ($this->modules->providers() as $nome => $provider) {
            if (!$this->modules->isEnabled($nome)) {
                continue; // ignora módulos desabilitados
            }
            $descricao = $provider->describe();
            $modulos[] = [
                'nome' => $nome,
                'rotas' => $descricao['routes'] ?? [],
            ];
        }

        return $modulos;
    }
}
