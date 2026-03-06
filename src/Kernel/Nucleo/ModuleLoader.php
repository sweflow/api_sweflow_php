<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Nucleo\CapabilityResolver;

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
        $root = dirname(__DIR__, 3);
        $this->stateFile = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_state.json';
        $this->cacheFile = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_cache.php';
        $this->enabled = $this->loadState();
    }

    public function discover(string $modulesPath): void
    {
        if (($_ENV['APP_ENV'] ?? 'local') === 'production' && is_file($this->cacheFile)) {
            $cached = @include $this->cacheFile;
            if (is_array($cached)) {
                foreach ($cached as $name => $data) {
                    if (isset($data['path']) && is_dir($data['path'])) {
                        $this->providers[$name] = new SimpleModuleProvider($name, $data['path']);
                        if (!array_key_exists($name, $this->enabled)) {
                            $this->enabled[$name] = true;
                        }
                    }
                }
            }
        }

        // src/Modules/* (Nativo + Plugins instalados aqui)
        // Agora todos os módulos ficam em src/Modules, sejam nativos ou instalados via dashboard.
        if (is_dir($modulesPath)) {
            foreach (scandir($modulesPath) as $module) {
                if ($module === '.' || $module === '..') continue;
                $moduleDir = rtrim($modulesPath, '/\\') . DIRECTORY_SEPARATOR . $module;
                if (!is_dir($moduleDir)) continue;
                
                // Tenta carregar via SimpleModuleProvider (convenção)
                // Se tiver composer.json com providers, o autoload do composer cuidaria se estivesse no vendor,
                // mas como está em src/Modules, o autoload PSR-4 "Src\Modules\" já pega.
                // Precisamos instanciar o Provider correto.
                
                // 1. Tenta achar classe Provider via PSR-4 padrão: Src\Modules\{Module}\{Module}Provider
                $providerClass = "Src\\Modules\\{$module}\\{$module}Provider"; // Ex: Src\Modules\Auth\AuthProvider (não existe no Auth atual, usa controller direto?)
                
                // O Auth atual não tem Provider, ele é carregado "magicamente" pelo SimpleModuleProvider?
                // O SimpleModuleProvider assume que não há classe provider e faz o trabalho sujo.
                
                // Se o módulo tiver um composer.json com "extra.sweflow.providers", devemos honrar.
                $composerJson = $moduleDir . DIRECTORY_SEPARATOR . 'composer.json';
                $loaded = false;
                
                if (file_exists($composerJson)) {
                    $meta = json_decode(file_get_contents($composerJson), true);
                    $providers = $meta['extra']['sweflow']['providers'] ?? [];
                    if (!empty($providers)) {
                        foreach ($providers as $pClass) {
                            if (class_exists($pClass)) {
                                try {
                                    $providerInstance = $this->container->make($pClass);
                                    if ($providerInstance instanceof ModuleProviderInterface) {
                                        $this->providers[$module] = $providerInstance;
                                        if (!array_key_exists($module, $this->enabled)) {
                                            $this->enabled[$module] = true;
                                        }
                                        $loaded = true;
                                        break; 
                                    }
                                } catch (\Throwable $e) {}
                            }
                        }
                    }
                }
                
                // Fallback para SimpleModuleProvider se não achou provider explícito
                if (!$loaded) {
                    $this->registerSimple($module, $moduleDir);
                }
            }
        }

        // vendor/sweflow/*/src/Modules/*
        $root = dirname(__DIR__, 3);
        $sweflowVendor = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow';
        if (is_dir($sweflowVendor)) {
            foreach (scandir($sweflowVendor) as $pkg) {
                if ($pkg === '.' || $pkg === '..') continue;
                $pkgDir = $sweflowVendor . DIRECTORY_SEPARATOR . $pkg;
                if (!is_dir($pkgDir)) continue;
                $vModules = $pkgDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
                if (!is_dir($vModules)) continue;
                foreach (scandir($vModules) as $module) {
                    if ($module === '.' || $module === '..') continue;
                    $moduleDir = $vModules . DIRECTORY_SEPARATOR . $module;
                    if (!is_dir($moduleDir)) continue;
                    $this->registerSimple($module, $moduleDir);
                }
            }
        }

        // storage/modules/*/src/Modules/*
        $storageModules = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules';
        if (is_dir($storageModules)) {
            foreach (scandir($storageModules) as $pkg) {
                if ($pkg === '.' || $pkg === '..') continue;
                $pkgDir = $storageModules . DIRECTORY_SEPARATOR . $pkg;
                if (!is_dir($pkgDir)) continue;
                $vModules = $pkgDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
                if (!is_dir($vModules)) continue;
                foreach (scandir($vModules) as $module) {
                    if ($module === '.' || $module === '..') continue;
                    $moduleDir = $vModules . DIRECTORY_SEPARATOR . $module;
                    if (!is_dir($moduleDir)) continue;
                    $this->registerSimple($module, $moduleDir);
                }
            }
        }

        // extra.sweflow.providers
        $installedPath = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        if (is_file($installedPath)) {
            $json = @file_get_contents($installedPath);
            $data = $json ? json_decode($json, true) : null;
            $packages = [];
            if (is_array($data)) {
                if (isset($data['packages']) && is_array($data['packages'])) {
                    $packages = $data['packages'];
                } elseif (isset($data[0]) || empty($data)) {
                    $packages = $data;
                }
            }
            foreach ($packages as $pkg) {
                $extra = $pkg['extra'] ?? null;
                $sweflow = is_array($extra) ? ($extra['sweflow'] ?? null) : null;
                $providers = is_array($sweflow) ? ($sweflow['providers'] ?? []) : [];
                if (!is_array($providers)) continue;
                foreach ($providers as $providerClass) {
                    if (!is_string($providerClass) || !class_exists($providerClass)) {
                        continue;
                    }
                    try {
                        $provider = $this->container->make($providerClass);
                        if ($provider instanceof ModuleProviderInterface) {
                            $name = (new \ReflectionClass($provider))->getShortName();
                            $this->providers[$name] = $provider;
                            if (!array_key_exists($name, $this->enabled)) {
                                $this->enabled[$name] = true;
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore bad providers
                    }
                }
            }
        }

        // Sync and cache
        $this->enabled = array_intersect_key($this->enabled, $this->providers);
        $this->persistState();
        if (($_ENV['APP_ENV'] ?? 'local') === 'production') {
            $this->cacheModules();
        }
    }

    public function bootAll(): void
    {
        $resolver = new CapabilityResolver($this->storageDir());
        foreach ($this->providers as $name => $provider) {
            if (!$this->isEnabled($name)) continue;
            if (!$this->isProviderActive($provider, $resolver)) continue;
            $provider->boot($this->container);
        }
    }

    public function registerRoutes(RouterInterface $router): void
    {
        $resolver = new CapabilityResolver($this->storageDir());
        foreach ($this->providers as $name => $provider) {
            if (!$this->isEnabled($name)) continue;
            if (!$this->isProviderActive($provider, $resolver)) continue;
            
            // Registra as rotas
            $provider->registerRoutes(new ModuleScopedRouter($router, $this, $name));
            
            // IMPORTANTE: Atualiza o provider no array de providers
            // O SimpleModuleProvider armazena rotas internamente durante o registerRoutes.
            // Se não atualizarmos a instância ou garantirmos que é a mesma referência,
            // o método describe() chamado depois pode não ter as rotas se o registerRoutes não tiver side-effects persistentes.
            // No caso do SimpleModuleProvider, $this->routes é preenchido em registerRoutes.
            // Como $provider é uma referência ao objeto em $this->providers, deve funcionar.
            // Mas vamos garantir que o describe() seja chamado DEPOIS de registerRoutes para popular a visualização.
        }
    }

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

    public function getModules(): array
    {
        $modules = [];
        // Precisamos garantir que as rotas foram registradas para que o describe() funcione corretamente.
        // O registerRoutes() é chamado no boot da Application, mas o StatusController (que chama getModules via LeitorModulos)
        // é executado em uma requisição separada.
        // Se o Application já deu boot, as rotas já foram registradas nas instâncias de providers em memória.
        // O container mantém o ModuleLoader como singleton, então $this->providers deve ter o estado correto.
        
        foreach ($this->providers as $name => $provider) {
            $desc = $provider->describe();
            $modules[] = [
                'name' => $name,
                'enabled' => $this->isEnabled($name),
                'protected' => $this->isProtected($name),
                'description' => $desc['description'] ?? '',
                'routes' => $desc['routes'] ?? [],
            ];
        }
        return $modules;
    }

    private function registerSimple(string $name, string $path): void
    {
        if (!isset($this->providers[$name])) {
            $this->providers[$name] = new SimpleModuleProvider($name, $path);
            if (!array_key_exists($name, $this->enabled)) {
                $this->enabled[$name] = true;
            }
        }
    }

    private function cacheModules(): void
    {
        $data = [];
        foreach ($this->providers as $name => $provider) {
            if ($provider instanceof SimpleModuleProvider) {
                $data[$name] = ['path' => $provider->getPath()];
            }
        }
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        @file_put_contents($this->cacheFile, $content);
    }

    private function loadState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $json = @file_get_contents($this->stateFile);
        if ($json === false) return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        foreach ($this->protectedModules as $prot) {
            $data[$prot] = true;
        }
        return array_map(fn($v) => (bool)$v, $data);
    }

    private function persistState(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->stateFile, json_encode($this->enabled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function storageDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage';
    }

    private function isProviderActive(ModuleProviderInterface $provider, CapabilityResolver $resolver): bool
    {
        $provides = $this->providerProvides($provider);
        if (empty($provides)) {
            return true;
        }
        $pluginId = $this->providerPluginId($provider);
        foreach ($provides as $cap) {
            $active = $resolver->resolve($cap);
            if ($active !== null && $active !== $pluginId) {
                return false;
            }
        }
        return true;
    }

    private function providerProvides(ModuleProviderInterface $provider): array
    {
        $file = (new \ReflectionClass($provider))->getFileName() ?: '';
        $pluginRoot = $this->guessPluginRoot($file);
        if (!$pluginRoot) return [];
        $pj = $pluginRoot . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($pj)) return [];
        $data = json_decode(@file_get_contents($pj), true) ?: [];
        $provides = $data['provides'] ?? [];
        return is_array($provides) ? array_values(array_filter($provides)) : [];
    }

    private function providerPluginId(ModuleProviderInterface $provider): string
    {
        $file = (new \ReflectionClass($provider))->getFileName() ?: '';
        $pluginRoot = $this->guessPluginRoot($file);
        return $pluginRoot ? basename($pluginRoot) : (new \ReflectionClass($provider))->getShortName();
    }

    private function guessPluginRoot(string $providerFile): ?string
    {
        if ($providerFile === '') return null;
        $dir = dirname($providerFile);
        for ($i = 0; $i < 4; $i++) {
            if (basename($dir) === 'src') {
                $root = dirname($dir);
                $composer = $root . DIRECTORY_SEPARATOR . 'composer.json';
                if (is_file($composer)) {
                    return $root;
                }
            }
            $dir = dirname($dir);
        }
        return null;
    }
}
