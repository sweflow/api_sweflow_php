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
        $webRoutes = $this->path . '/Routes/web.php';
        if (file_exists($webRoutes)) {
             $container = null; 
             require $webRoutes;

             if ($router instanceof ModuleScopedRouter) {
                 $this->routes = $router->getRegisteredRoutes();
             }
        }
    }

    public function describe(): array
    {
        return [
            'name' => $this->name,
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
}
