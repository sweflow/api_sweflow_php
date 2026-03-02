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

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
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
            }
        }
    }

    public function bootAll(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }
    }

    public function registerRoutes(RouterInterface $router): void
    {
        foreach ($this->providers as $provider) {
            $provider->registerRoutes($router);
        }
    }

    /**
     * Returns loaded providers keyed by module name.
     */
    public function providers(): array
    {
        return $this->providers;
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
