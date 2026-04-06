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

        // Não remove providers desativados — apenas persiste o estado false.
        // O dashboard precisa ver todos os módulos, inclusive os desativados.
        // O roteamento já respeita isEnabled() ao registrar rotas.

        $this->enabled = array_intersect_key($this->enabled, $this->providers);

        // Preserva entradas false no state (módulos desinstalados) mesmo que não estejam nos providers
        foreach ($this->loadState() as $name => $val) {
            if ($val === false) {
                $this->enabled[$name] = false;
            }
        }

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
        $json = file_get_contents($installedPath);
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
                // Módulos externos (Composer) usam a conexão secundária pdo.modules
                $provider = $this->makeWithModulesPdo($providerClass);
                if ($provider instanceof ModuleProviderInterface) {
                    $name = $provider->getName();

                    // Se o módulo foi explicitamente desinstalado (state = false), não registra
                    if (array_key_exists($name, $this->enabled) && $this->enabled[$name] === false) {
                        continue;
                    }

                    // Composer providers always win over SimpleModuleProvider stubs
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

        $cached = include $this->cacheFile;
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

        // Tenta carregar um provider real se o módulo tiver composer.json com extra.sweflow.providers
        $composerFile = $moduleDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerFile)) {
            $meta      = json_decode((string) file_get_contents($composerFile), true) ?? [];
            $providers = $meta['extra']['sweflow']['providers'] ?? [];
            if (is_array($providers)) {
                foreach ($providers as $providerClass) {
                    if (!is_string($providerClass) || !class_exists($providerClass)) {
                        continue;
                    }
                    try {
                        $provider = $this->container->make($providerClass);
                        if ($provider instanceof ModuleProviderInterface) {
                            $name = $provider->getName() ?: $module;
                            $this->providers[$name] = $provider;
                            $this->setEnabledIfNotExist($name);
                            return;
                        }
                    } catch (\Throwable) {
                        // provider class exists but failed to instantiate — fall through to SimpleModuleProvider
                    }
                }
            }
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
        // Se o módulo foi explicitamente desinstalado (state = false), não registra
        if (array_key_exists($name, $this->enabled) && $this->enabled[$name] === false) {
            return;
        }
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
        file_put_contents($this->cacheFile, $content);
    }

    private function loadState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $json = file_get_contents($this->stateFile);
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
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->stateFile, json_encode($this->enabled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
                $raw = file_get_contents($composer);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    $provides = $data['extra']['sweflow']['provides'] ?? [];
                    if (!empty($provides)) return $provides;
                }
            }

            $plugin = $dir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (is_file($plugin)) {
                $raw = file_get_contents($plugin);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    $provides = $data['provides'] ?? [];
                    if (!empty($provides)) return $provides;
                }
            }
            
            $dir = dirname($dir);
        }
        
        return [];
    }

    private function providerPluginId(ModuleProviderInterface $provider): string
    {
        // getName() está na interface — sempre disponível
        $name = $provider->getName();
        if ($name !== '') {
            return $name;
        }

        // Fallback via Reflection para casos onde getName() retorna vazio
        $ref  = new \ReflectionClass($provider);
        $file = $ref->getFileName() ?: '';

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

    /**
     * Instancia um provider de módulo externo usando a conexão correta.
     *
     * Lógica de decisão (controlada pelo core):
     *   1. Instancia o provider com a conexão principal para ler preferredConnection()
     *   2. Se preferredConnection() === 'core' → usa PDO::class
     *   3. Se preferredConnection() === 'modules' ou 'auto' (externo) → usa pdo.modules
     *   4. Se pdo.modules não estiver configurado → cai para PDO::class com aviso
     */
    private function makeWithModulesPdo(string $providerClass): object
    {
        // Primeira instância com conexão principal só para ler a preferência
        try {
            $tempProvider = $this->container->make($providerClass);
        } catch (\Throwable) {
            return $this->container->make($providerClass);
        }

        $preference = ($tempProvider instanceof ModuleProviderInterface && method_exists($tempProvider, 'preferredConnection'))
            ? $tempProvider->preferredConnection()
            : 'auto';

        // Core valida e decide — o módulo apenas declara preferência
        $useModulesConnection = match ($preference) {
            'core'    => false,
            'modules' => true,
            default   => true, // 'auto' para módulos externos = pdo.modules
        };

        if (!$useModulesConnection) {
            $this->logConnection($providerClass, 'core (declarado pelo módulo)');
            return $tempProvider;
        }

        try {
            $modulesPdo = $this->container->make('pdo.modules');
        } catch (\Throwable) {
            $this->logConnection($providerClass, 'core (pdo.modules não disponível — fallback)');
            return $tempProvider;
        }

        // Verifica se pdo.modules é diferente de PDO::class (segunda conexão real)
        $corePdo = null;
        try { $corePdo = $this->container->make(\PDO::class); } catch (\Throwable) {}

        if ($modulesPdo === $corePdo) {
            $this->logConnection($providerClass, 'core (DB2_* não configurado — usando conexão principal)');
            return $tempProvider;
        }

        // Recria com container derivado usando pdo.modules como PDO::class
        $derived = clone $this->container;
        $derived->bind(\PDO::class, static fn() => $modulesPdo, true);

        $this->logConnection($providerClass, 'modules (DB2_*)');

        try {
            return $derived->make($providerClass);
        } catch (\Throwable) {
            return $tempProvider;
        }
    }

    private function logConnection(string $providerClass, string $connection): void
    {
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $short = basename(str_replace('\\', '/', $providerClass));
            // Tenta obter o driver da conexão usada para log mais rico
            $driver = '';
            try {
                $pdoKey = str_contains($connection, 'modules') ? 'pdo.modules' : \PDO::class;
                $pdo    = $this->container->make($pdoKey);
                $driver = ' [' . $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) . ']';
            } catch (\Throwable) {}
            error_log("[ModuleLoader] {$short} → conexão: {$connection}{$driver}");
        }
    }
}
