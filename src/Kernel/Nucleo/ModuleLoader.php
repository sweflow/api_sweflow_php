<?php

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Nucleo\SimpleModuleProvider;

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
    private string $cacheFile;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->stateFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_state.json';
        $this->cacheFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_cache.php';
        $this->enabled = $this->loadState();
    }

    /**
    * Discovers module providers inside the given directory.
    */
    public function discover(string $modulesPath): void
    {
        // Se estivermos em produção e o cache existir, usa o cache
        if (($_ENV['APP_ENV'] ?? 'local') === 'production' && file_exists($this->cacheFile)) {
            $cachedModules = require $this->cacheFile;
            if (is_array($cachedModules)) {
                $this->rehydrateProviders($cachedModules);
                return;
            }
        }

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

        // Se estiver em produção, gera o cache
        if (($_ENV['APP_ENV'] ?? 'local') === 'production') {
            $this->cacheModules();
        }
    }

    private function rehydrateProviders(array $cachedModules): void
    {
        foreach ($cachedModules as $module => $data) {
            // Em SimpleModuleProvider, só precisamos saber o caminho base
            // Se no futuro tivermos providers customizados, precisaremos salvar a classe no cache
            if (isset($data['path']) && is_dir($data['path'])) {
                 $this->providers[$module] = new SimpleModuleProvider($module, $data['path']);
            }
        }
    }

    private function cacheModules(): void
    {
        $data = [];
        foreach ($this->providers as $name => $provider) {
            if ($provider instanceof SimpleModuleProvider) {
                $data[$name] = [
                    'path' => $provider->getPath(),
                ];
            }
        }
        
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        @file_put_contents($this->cacheFile, $content);
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

    private function loadProvider(string $moduleName, string $modulePath): ?ModuleProviderInterface
    {
        // Convenção: SimpleModuleProvider
        // Apenas cria um provider genérico que aponta para o diretório
        // O SimpleModuleProvider precisa ser ajustado para guardar o path
        /** @var ModuleProviderInterface */
        return new SimpleModuleProvider($moduleName, $modulePath);
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
