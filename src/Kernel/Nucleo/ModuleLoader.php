<?php

namespace Src\Nucleo;

use Src\Contracts\ContainerInterface;
use Src\Contracts\ModuleProviderInterface;
use Src\Contracts\RouterInterface;
use Src\Nucleo\SimpleModuleProvider;

class ModuleLoader
{
    private ContainerInterface $container;
    /** @var array<string, ModuleProviderInterface> */
    private array $providers = [];
    /** @var array<string,bool> */
    private array $enabled = [];
    /** @var string[] */
    private array $protectedModules = ['Auth', 'Usuario'];
    private string $stateFile;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->stateFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_state.json';
        $this->enabled = $this->loadState();
    }

    /**
    * Discovers module providers inside the given directory.
    */
    public function discover(string $modulesPath): void
    {
        if (!is_dir($modulesPath)) {
            return;
        }

        foreach (scandir($modulesPath) as $module) {
            if ($module === '.' || $module === '..') {
                continue;
            }
            $moduleDir = rtrim($modulesPath, '/\\') . DIRECTORY_SEPARATOR . $module;
            if (!is_dir($moduleDir)) {
                continue;
            }
            $provider = $this->loadProvider($module, $moduleDir);
            if ($provider instanceof ModuleProviderInterface) {
                $this->providers[$module] = $provider;
                if (!array_key_exists($module, $this->enabled)) {
                    $this->enabled[$module] = true; // default: habilitado
                }
            }
        }

        // Remove estados de módulos que não existem mais
        $this->enabled = array_intersect_key($this->enabled, $this->providers);

        $this->persistState();
    }

    public function bootAll(): void
    {
        foreach ($this->providers as $name => $provider) {
            if (!$this->isEnabled($name)) {
                continue;
            }
            $provider->boot($this->container);
        }
    }

    public function registerRoutes(RouterInterface $router): void
    {
        foreach ($this->providers as $name => $provider) {
            if (!$this->isEnabled($name)) {
                continue;
            }
            $scopedRouter = new ModuleScopedRouter($router, $this, $name);
            $provider->registerRoutes($scopedRouter);
        }
    }

    /**
     * Returns loaded providers keyed by module name.
     */
    public function providers(): array
    {
        return $this->providers;
    }

    public function isEnabled(string $module): bool
    {
        return $this->enabled[$module] ?? true;
    }

    public function isProtected(string $module): bool
    {
        return in_array($module, $this->protectedModules, true);
    }

    public function setEnabled(string $module, bool $enabled): void
    {
        if (!isset($this->providers[$module]) && !array_key_exists($module, $this->enabled)) {
            return;
        }
        if ($this->isProtected($module) && $enabled === false) {
            $this->enabled[$module] = true;
            $this->persistState();
            return;
        }
        $this->enabled[$module] = $enabled;
        $this->persistState();
    }

    public function toggle(string $module): bool
    {
        if ($this->isProtected($module)) {
            return $this->isEnabled($module);
        }
        $new = !($this->enabled[$module] ?? true);
        $this->setEnabled($module, $new);
        return $new;
    }

    public function states(): array
    {
        $list = [];
        foreach ($this->providers as $name => $_) {
            $list[$name] = $this->isEnabled($name);
        }
        ksort($list);
        return $list;
    }

    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $json = @file_get_contents($this->stateFile);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $name => $enabled) {
            $normalized[$name] = (bool) $enabled;
        }

        foreach ($this->protectedModules as $protected) {
            $normalized[$protected] = true; // protegidos sempre habilitados
        }

        return $normalized;
    }

    private function persistState(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->stateFile, json_encode($this->enabled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function loadProvider(string $module, string $moduleDir): ?ModuleProviderInterface
    {
        $moduleFile = $moduleDir . DIRECTORY_SEPARATOR . 'module.php';
        if (file_exists($moduleFile)) {
            $providerClass = include $moduleFile;
            if (is_array($providerClass)) {
                return SimpleModuleProvider::fromConfig($module, $providerClass);
            }
            if (is_string($providerClass)) {
                if (!class_exists($providerClass)) {
                    return null;
                }
                return $this->container->make($providerClass);
            }
            if ($providerClass instanceof ModuleProviderInterface) {
                return $providerClass;
            }
        }

        $classFile = $moduleDir . DIRECTORY_SEPARATOR . 'Module.php';
        if (file_exists($classFile)) {
            require_once $classFile;
        }
        $className = "Src\\Modules\\{$module}\\Module";
        if (class_exists($className)) {
            $instance = $this->container->make($className);
            if ($instance instanceof ModuleProviderInterface) {
                return $instance;
            }
        }

        $routesFile = $moduleDir . DIRECTORY_SEPARATOR . 'routes.php';
        $bindingsFile = $moduleDir . DIRECTORY_SEPARATOR . 'bindings.php';
        $legacyWeb = $moduleDir . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'web.php';
        $routes = file_exists($routesFile) ? include $routesFile : [];
        $bindings = file_exists($bindingsFile) ? include $bindingsFile : [];
        $legacyRoutes = file_exists($legacyWeb) ? $this->loadLegacyWebRoutes($legacyWeb) : [];

        if (!empty($routes) || !empty($bindings) || !empty($legacyRoutes)) {
            $mergedRoutes = array_merge($routes ?: [], $legacyRoutes ?: []);
            return new SimpleModuleProvider($module, $bindings ?: [], $mergedRoutes);
        }

        return null;
    }

    private function loadLegacyWebRoutes(string $file): array
    {
        $captured = [];
        $loader = function () use ($file, &$captured) {
            $GLOBALS['routes'] = [];
            include $file;
            $captured = $GLOBALS['routes'] ?? [];
            unset($GLOBALS['routes']);
        };
        $loader();

        $converted = array_map(function ($route) {
            $method = $route['method'] ?? 'GET';
            $uri = $route['uri'] ?? '';
            $handler = $route['handler'] ?? null;
            $middleware = $route['middleware'] ?? [];
            if ($uri === '' || $handler === null) {
                return null;
            }

            $middlewares = is_array($middleware) ? $middleware : ($middleware ? [$middleware] : []);

            return [
                'method' => $method,
                'uri' => $uri,
                'action' => $handler,
                'middlewares' => $middlewares,
            ];
        }, array_filter($captured));

        return array_values(array_filter($converted));
    }
}
