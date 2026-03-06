<?php

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;

/**
 * Lightweight provider created from plain config arrays so modules stay minimal.
 */
class SimpleModuleProvider implements ModuleProviderInterface
{
    private string $name;
    private string $path;
    private array $bindings = [];
    private array $routes = [];
    private array $metadata = [];

    public function __construct(string $name, string $path)
    {
        $this->name = $name;
        $this->path = $path;
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        // Carrega rotas se existirem
        $webRoutes = $this->path . '/Routes/web.php';
        if (file_exists($webRoutes)) {
            // Em vez de include direto que executa, vamos apenas salvar o caminho
            // O registro real acontece em registerRoutes passando o router
        }

        // Carrega metadados (composer.json ou plugin.json)
        $composerJson = $this->path . '/composer.json';
        if (file_exists($composerJson)) {
            $data = json_decode(file_get_contents($composerJson), true);
            $this->metadata['description'] = $data['description'] ?? '';
            $this->metadata['version'] = $data['version'] ?? '1.0.0';
        }
        
        $pluginJson = $this->path . '/plugin.json';
        if (file_exists($pluginJson)) {
            $data = json_decode(file_get_contents($pluginJson), true);
            if (!empty($data['description'])) $this->metadata['description'] = $data['description'];
            if (!empty($data['version'])) $this->metadata['version'] = $data['version'];
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function boot(ContainerInterface $container): void
    {
        // Zero config: bindings são automáticos pelo container
    }

    public function registerRoutes(RouterInterface $router): void
    {
        // Tenta achar arquivo de rotas.
        // Padrão 1: raiz/Routes/web.php (Módulos Nativos)
        // Padrão 2: raiz/src/Routes/routes.php (Plugins Sweflow instalados)
        
        $candidates = [
            $this->path . '/Routes/web.php',
            $this->path . '/src/Routes/routes.php',
            $this->path . '/src/Routes/web.php',
        ];
        
        $routeFile = null;
        foreach ($candidates as $f) {
            if (file_exists($f)) {
                $routeFile = $f;
                break;
            }
        }

        if ($routeFile) {
             $container = null; 
             require $routeFile;

             if ($router instanceof ModuleScopedRouter) {
                 $this->routes = $router->getRegisteredRoutes();
             }
        }
    }

    public function describe(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->metadata['description'] ?? '',
            'version' => $this->metadata['version'] ?? '1.0.0',
            'routes' => array_map(function ($route) {
                return [
                    'method' => strtoupper($route['method'] ?? 'GET'),
                    'uri' => $route['uri'] ?? '',
                    'protected' => !empty($route['middlewares']),
                    'tipo' => !empty($route['middlewares']) ? 'privada' : 'pública',
                ];
            }, $this->routes),
        ];
    }

    public function onInstall(): void
    {
        // Default empty implementation for simple modules
    }

    public function onEnable(): void
    {
        // Default empty implementation for simple modules
    }

    public function onDisable(): void
    {
        // Default empty implementation for simple modules
    }

    public function onUninstall(): void
    {
        // Default empty implementation for simple modules
    }
}
