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
    private string $stateFile;
    private string $cacheFile;
    /** @var string[] */
    private array $protectedModules = [];

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
        $this->loadCachedProviders();

        if (is_dir($modulesPath)) {
            $this->discoverModulesDirectory($modulesPath);
        }

        $root = dirname(__DIR__, 3);
        $this->discoverVendorModules($root);
        $this->discoverStorageModules($root);
        $this->discoverComposerProviders($root);

        $this->enabled = array_intersect_key($this->enabled, $this->providers);
        $this->persistState();
        if (($_ENV['APP_ENV'] ?? 'local') === 'production') {
            $this->cacheModules();
        }
    }

    private function discoverVendorModules(string $root): void
    {
        $sweflowVendor = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow';
        $this->discoverPackageModules($sweflowVendor);
    }

    private function discoverStorageModules(string $root): void
    {
        $storageModules = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules';
        $this->discoverPackageModules($storageModules);
    }

    private function discoverPackageModules(string $packagesRoot): void
    {
        if (!is_dir($packagesRoot)) {
            return;
        }
        foreach (scandir($packagesRoot) as $pkg) {
            if ($pkg === '.' || $pkg === '..') continue;
            $vModules = $packagesRoot . DIRECTORY_SEPARATOR . $pkg . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
            if (!is_dir($vModules)) continue;
            foreach (scandir($vModules) as $module) {
                if ($module === '.' || $module === '..') continue;
                $moduleDir = $vModules . DIRECTORY_SEPARATOR . $module;
                if (is_dir($moduleDir)) {
                    $this->registerSimple($module, $moduleDir);
                }
            }
        }
    }

    private function discoverComposerProviders(string $root): void
    {
        $installedPath = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($installedPath)) {
            return;
        }
        $packages = $this->loadInstalledPackages($installedPath);
        foreach ($packages as $pkg) {
            $this->registerComposerPackageProviders($pkg);
        }
    }

    private function loadInstalledPackages(string $installedPath): array
    {
        $json = @file_get_contents($installedPath);
        if (!$json) return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        if (isset($data['packages']) && is_array($data['packages'])) {
            return $data['packages'];
        }
        return $data;
    }

    private function registerComposerPackageProviders(array $pkg): void
    {
        $extra    = $pkg['extra'] ?? null;
        $sweflow  = is_array($extra) ? ($extra['sweflow'] ?? null) : null;
        $providers = is_array($sweflow) ? ($sweflow['providers'] ?? []) : [];
        if (!is_array($providers)) return;

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
            } catch (\Throwable) {
                // ignore bad providers
            }
        }
    }

    private function loadCachedProviders(): void
    {
        if (($_ENV['APP_ENV'] ?? 'local') !== 'production' || !is_file($this->cacheFile)) {
            return;
        }

        $cached = @include $this->cacheFile;
        if (!is_array($cached)) {
            return;
        }

        foreach ($cached as $name => $data) {
            if (!isset($data['path']) || !is_dir($data['path'])) {
                continue;
            }

            $this->providers[$name] = new SimpleModuleProvider($name, $data['path']);
            $this->setEnabledIfNotExist($name);
        }
    }

    private function discoverModulesDirectory(string $modulesPath): void
    {
        $modules = scandir($modulesPath);
        foreach ($modules as $module) {
            if ($module === '.' || $module === '..') {
                continue;
            }

            $this->processModuleDirectory($modulesPath, $module);
        }
    }

    private function processModuleDirectory(string $modulesPath, string $module): void
    {
        $moduleDir = rtrim($modulesPath, '/\\') . DIRECTORY_SEPARATOR . $module;
        if (!is_dir($moduleDir)) {
            return;
        }

        $this->providers[$module] = new SimpleModuleProvider($module, $moduleDir);
        $this->setEnabledIfNotExist($module);
    }

    private function setEnabledIfNotExist(string $name): void
    {
        if (!array_key_exists($name, $this->enabled)) {
            $this->enabled[$name] = true;
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
            @mkdir($dir, 0755, true);
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
            // Se o módulo não declara capabilities, ele está sempre ativo (se enabled)
            return true;
        }
        
        $pluginId = $this->providerPluginId($provider);
        
        foreach ($provides as $cap) {
            $active = $resolver->resolve($cap);
            
            // Se a capability não tem provider definido, ou se o definido é este plugin
            if ($active === null) {
                // Auto-claim: Se ninguém tem essa capability, eu assumo
                $resolver->setProvider($cap, $pluginId);
                return true;
            }
            
            if ($active !== $pluginId) {
                // Existe outro provider ativo para esta capability.
                // Mas espera: capabilities_registry armazena o nome da PASTA (ex: 'Email').
                // O pluginId deve bater com o nome da pasta.
                
                // Normaliza para comparação (case insensitive e limpeza de prefixos se necessário)
                // O registry salva 'Email', o pluginId retorna 'Email'.
                if (strcasecmp($active, $pluginId) !== 0) {
                     return false;
                }
            }
        }
        return true;
    }

    private function providerProvides(ModuleProviderInterface $provider): array
    {
        // Try to find composer.json first (modern modules)
        $ref = new \ReflectionClass($provider);
        $file = $ref->getFileName() ?: '';
        if (empty($file)) return []; // Fix for internal classes or eval'd code
        
        $dir = dirname($file);
        
        // Walk up to find composer.json or plugin.json
        for ($i = 0; $i < 4; $i++) {
            // Check boundaries
            if (empty($dir) || $dir === '.' || $dir === '/' || $dir === '\\') break;

            $composer = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($composer)) {
                $data = json_decode(file_get_contents($composer), true);
                $provides = $data['extra']['sweflow']['provides'] ?? [];
                if (!empty($provides)) return $provides;
            }
            
            $plugin = $dir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (is_file($plugin)) {
                $data = json_decode(file_get_contents($plugin), true);
                $provides = $data['provides'] ?? [];
                if (!empty($provides)) return $provides;
            }
            
            $dir = dirname($dir);
        }
        
        return [];
    }

    private function providerPluginId(ModuleProviderInterface $provider): string
    {
        // 1. Explicit name (SimpleModuleProvider or Custom Provider with getName)
        if (method_exists($provider, 'getName')) {
            $name = $provider->getName();
            if (!empty($name)) {
                return $name;
            }
        }
        
        // 2. Reflection fallback
        $ref = new \ReflectionClass($provider);
        $file = $ref->getFileName() ?: '';
        
        // 3. Try to guess from composer.json "name" if available
        $root = $this->guessPluginRoot($file);
        if ($root) {
            // Priority:
            // A. If root is inside src/Modules/NAME, use NAME (folder name)
            // B. Use composer.json name? (No, registry uses folder/ID)
            
            // Check if root is a direct child of src/Modules
            $parent = dirname($root);
            if (basename($parent) === 'Modules' && basename(dirname($parent)) === 'src') {
                return basename($root);
            }
            
            // Fallback for plugins/NAME or other structures
            return basename($root);
        }

        // 4. Fallback: Parse path manually if guessPluginRoot failed or file is weird
        if (str_contains($file, 'src' . DIRECTORY_SEPARATOR . 'Modules')) {
            $parts = explode(DIRECTORY_SEPARATOR, $file);
            $idx = array_search('Modules', $parts);
            if ($idx !== false && isset($parts[$idx + 1])) {
                return $parts[$idx + 1];
            }
        }
        
        // 5. Hardcode hack: if it's the EmailServiceProvider, force "Email"
        if ($ref->getShortName() === 'EmailServiceProvider') {
            return 'Email';
        }
        
        return $ref->getShortName();
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
