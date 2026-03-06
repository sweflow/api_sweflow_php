<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Nucleo\ModuleLoader;
use Src\Kernel\Nucleo\PluginManager;

class LeitorModulos
{
    public function __construct(
        private ModuleLoader $modules,
        private PluginManager $pluginManager
    ) {}

    public function ler(): array
    {
        $installed = $this->pluginManager->read();
        $modulos = [];
        
        foreach ($this->modules->providers() as $nome => $provider) {
            // Agora que plugins são instalados como módulos nativos, o ModuleLoader os carrega.
            // Mas precisamos distinguir o que deve aparecer como "Módulo Instalado".
            // A lógica atual filtra pelo registro de plugins OU se é protegido (Auth/Usuario).
            // Isso funciona bem: Auth/Usuario aparecem sempre. Plugins só se instalados via PluginManager.
            // Mas se eu instalar manualmente em src/Modules/NovoModulo, ele não aparecerá se não estiver no registro.
            
            // Para "Módulos Nativos" criados manualmente em src/Modules, eles deveriam aparecer?
            // Sim, se estão em src/Modules, são parte do sistema.
            // O filtro `!isset($installed[$nome])` esconde módulos nativos que não foram instalados via Marketplace.
            // Vamos remover esse filtro restritivo para `lerCompleto` (Dashboard), permitindo ver tudo o que o ModuleLoader carregou.
            
            if (!$this->modules->isEnabled($nome)) {
                continue;
            }
            $descricao = $provider->describe();
            $modulos[] = [
                'nome' => $nome,
                'rotas' => $descricao['routes'] ?? [],
            ];
        }

        return $modulos;
    }

    public function lerCompleto(): array
    {
        $installed = $this->pluginManager->read();
        $modulos = [];
        
        foreach ($this->modules->providers() as $nome => $provider) {
            // No Dashboard, queremos ver TODOS os módulos carregados pelo sistema (src/Modules + vendor + plugins).
            // Não precisamos filtrar pelo registro do PluginManager, pois o ModuleLoader é a fonte da verdade do que está rodando.
            
            $desc = $provider->describe();
            $modulos[] = [
                'name' => $nome,
                'enabled' => $this->modules->isEnabled($nome),
                'protected' => $this->modules->isProtected($nome),
                'description' => $desc['description'] ?? '',
                'version' => $desc['version'] ?? '1.0.0',
                'routes' => $desc['routes'] ?? [],
            ];
        }
        return $modulos;
    }

    public function alternar(string $modulo): bool
    {
        return $this->modules->toggle($modulo);
    }
}
