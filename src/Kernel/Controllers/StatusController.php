<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\Nucleo\InfoServidor;
use Src\Kernel\Nucleo\LeitorModulos;
use Src\Kernel\Nucleo\Resposta;

class StatusController
{
    public function __construct(
        private InfoServidor $servidor,
        private LeitorModulos $modulos,
        private Resposta $resposta
    ) {}

    public function index(): void
    {
        $this->resposta->json(['status' => 'ok']);
    }

    public function modules(): void
    {
        $this->resposta->json($this->modulos->lerCompleto());
    }

    public function toggle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $module = $input['module'] ?? '';
        
        if (!$module) {
            $this->resposta->json(['error' => 'Module name required'], 400);
            return;
        }

        // Se for um módulo gerenciado pelo PluginManager, usa ele
        // Se for um módulo interno (SimpleModuleProvider), usa o ModuleLoader toggle
        
        // Aqui temos uma duplicidade de gestão: PluginManager vs ModuleLoader
        // O ModuleLoader é o runtime, o PluginManager é o lifecycle (install/uninstall)
        // Para toggle (enable/disable), o ModuleLoader mantém o estado em storage/modules_state.json
        // O PluginManager mantém em storage/plugins_registry.json
        
        // Vamos usar o ModuleLoader pois ele controla o que é carregado no boot
        // Mas idealmente deveríamos sincronizar.
        // O ModuleLoader lê modules_state.json. O PluginManager lê plugins_registry.json.
        // O ideal é que o PluginManager.enable() chame ModuleLoader.enable() ou vice-versa.
        
        // Por enquanto, o ModuleLoader é quem define se o provider é registrado ou não no container.
        $newState = $this->modulos->alternar($module);
        
        // Sincroniza com PluginManager se possível
        // (Isso exigiria injetar PluginManager aqui, mas vamos manter simples por agora)
        
        $this->resposta->json(['module' => $module, 'enabled' => $newState]);
    }
}
