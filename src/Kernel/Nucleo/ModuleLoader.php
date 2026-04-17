<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;

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
    private array $protectedModules = ['Auth', 'Usuario'];
    /** @var array<string, array<string>> Cache de provides por classe de provider */
    private array $providesCache = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $root = dirname(__DIR__, 3);
        $this->stateFile = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_state.json';
        $this->cacheFile = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules_cache.json';
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
        $persistedState = $this->loadState();
        foreach ($persistedState as $name => $val) {
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
        $vupiVendor = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'vupi.us';
        $this->discoverPackageModules($vupiVendor);
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
        $vupiExtra = is_array($extra) ? ($extra['vupi.us'] ?? null) : null;
        $providers = is_array($vupiExtra) ? ($vupiExtra['providers'] ?? []) : [];
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

        // Usa JSON em vez de PHP executável — elimina risco de RCE se o arquivo for comprometido
        $raw = is_readable($this->cacheFile) ? file_get_contents($this->cacheFile) : false;
        if ($raw === false) {
            return;
        }
        $cached = json_decode($raw, true);
        if (!is_array($cached)) {
            return;
        }

        foreach ($cached as $name => $data) {
            if (!is_array($data) || !isset($data['path']) || !is_dir($data['path'])) {
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

        // Valida apenas módulos EXTERNOS (não os módulos internos do kernel em src/Modules/).
        // Módulos internos como Auth, Usuario, IdeModuleBuilder são parte do sistema
        // e não precisam de validação de segurança — eles já são confiáveis.
        $isInternalModule = $this->isInternalModulesPath($modulesPath);
        if (!$isInternalModule) {
            try {
                ModuleGuard::assertModuleAllowed($module, $moduleDir);
            } catch (\RuntimeException $e) {
                error_log('[ModuleGuard] Módulo externo bloqueado: ' . $e->getMessage());
                return;
            }
        }

        // Tenta carregar um provider real se o módulo tiver composer.json com extra.vupi.us.providers
        $composerFile = $moduleDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerFile)) {
            $meta      = json_decode((string) file_get_contents($composerFile), true) ?? [];
            $providers = $meta['extra']['vupi.us']['providers'] ?? [];
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

        // Detecção automática por convenção de nome — sem composer.json obrigatório.
        // Procura por Providers/{Module}ServiceProvider ou Providers/{Module}Provider
        // Usa class_exists() com autoloader — nunca require_once direto (evita fatal errors)
        $conventionProviders = [
            'Src\\Modules\\' . $module . '\\Providers\\' . $module . 'ServiceProvider',
            'Src\\Modules\\' . $module . '\\Providers\\' . $module . 'Provider',
        ];
        foreach ($conventionProviders as $providerClass) {
            // Verifica o arquivo fisicamente antes de tentar autoload
            // para evitar fatal errors de classes com interface incompleta
            $providerFile = $moduleDir . DIRECTORY_SEPARATOR . 'Providers'
                . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $providerClass)) . '.php';

            if (!is_file($providerFile)) {
                continue;
            }

            // Lê o arquivo para verificar se implementa ModuleProviderInterface
            // Se sim, verifica se tem todos os métodos obrigatórios antes de incluir
            $fileContent = (string) file_get_contents($providerFile);
            if (str_contains($fileContent, 'ModuleProviderInterface')) {
                $requiredMethods = ['registerRoutes', 'boot', 'describe', 'getName', 'setName',
                                    'onInstall', 'onEnable', 'onDisable', 'onUninstall'];
                foreach ($requiredMethods as $method) {
                    if (!preg_match('/function\s+' . $method . '\s*\(/', $fileContent)) {
                        // Método obrigatório ausente — não instancia, cai para SimpleModuleProvider
                        error_log("[ModuleLoader] {$providerClass} não implementa '{$method}' — usando SimpleModuleProvider");
                        continue 2;
                    }
                }
            }

            if (!class_exists($providerClass)) {
                continue;
            }
            try {
                $ref = new \ReflectionClass($providerClass);
                if (!$ref->isInstantiable()) continue;
                if (!$ref->implementsInterface(ModuleProviderInterface::class)) continue;

                $provider = $this->container->make($providerClass);
                if ($provider instanceof ModuleProviderInterface) {
                    $name = $provider->getName() ?: $module;
                    $this->providers[$name] = $provider;
                    $this->setEnabledIfNotExist($name);
                    return;
                }
            } catch (\Throwable $e) {
                error_log("[ModuleLoader] Provider {$providerClass} falhou: " . $e->getMessage());
            }
        }

        $this->providers[$module] = new SimpleModuleProvider($module, $moduleDir);
        $this->setEnabledIfNotExist($module);
    }

    /**
     * Retorna o container correto para o provider.
     * Verifica preferredConnection() ou connection.php do módulo.
     * Se usar 'modules', retorna container com PDO::class = pdo.modules.
     */
    private function resolveContainerForProvider(ModuleProviderInterface $provider): ContainerInterface
    {
        $pref = $this->getProviderConnection($provider);

        // Resolve 'auto' usando DEFAULT_MODULE_CONNECTION
        if ($pref === 'auto') {
            $default = trim((string) ($_ENV['DEFAULT_MODULE_CONNECTION'] ?? getenv('DEFAULT_MODULE_CONNECTION') ?: 'core'));
            $pref = in_array($default, ['core', 'modules'], true) ? $default : 'core';
        }

        if ($pref !== 'modules') {
            return $this->container;
        }

        // Tenta obter pdo.modules
        try {
            $modulesPdo = $this->container->make('pdo.modules');
        } catch (\Throwable) {
            return $this->container;
        }

        // Verifica se é realmente diferente do core
        try {
            $corePdo = $this->container->make(\PDO::class);
            if ($modulesPdo === $corePdo) {
                return $this->container;
            }
        } catch (\Throwable) {}

        $derived = clone $this->container;
        $derived->bind(\PDO::class, static fn() => $modulesPdo, true);
        return $derived;
    }

    /**
     * Determina a conexão preferida do provider.
     * Prioridade: preferredConnection() > connection.php > 'core'
     */
    private function getProviderConnection(ModuleProviderInterface $provider): string
    {
        // 1. Método preferredConnection() declarado no provider
        if (method_exists($provider, 'preferredConnection')) {
            try {
                $pref = (string) $provider->preferredConnection();
                if (in_array($pref, ['core', 'modules', 'auto'], true)) {
                    return $pref;
                }
            } catch (\Throwable) {}
        }

        // 2. SimpleModuleProvider — lê connection.php do módulo
        if ($provider instanceof SimpleModuleProvider) {
            $connFile = $provider->getPath() . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
            if (is_file($connFile)) {
                try {
                    $val = (string)(include $connFile);
                    if (in_array($val, ['core', 'modules', 'auto'], true)) {
                        return $val;
                    }
                } catch (\Throwable) {}
            }
            return 'core';
        }

        // 3. Provider real (classe PHP) — tenta descobrir o path via Reflection
        try {
            $ref  = new \ReflectionClass($provider);
            $file = $ref->getFileName() ?: '';
            if ($file !== '') {
                // Sobe até encontrar Database/connection.php
                $dir = dirname($file);
                for ($i = 0; $i < 5; $i++) {
                    $connFile = $dir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
                    if (is_file($connFile)) {
                        $val = (string)(include $connFile);
                        if (in_array($val, ['core', 'modules', 'auto'], true)) {
                            return $val;
                        }
                    }
                    $dir = dirname($dir);
                }
            }
        } catch (\Throwable) {}

        return 'core';
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

            $container = $this->resolveContainerForProvider($provider);

            // Módulos internos (protegidos) executam diretamente — erros devem subir
            // Módulos externos são isolados — erros não derrubam o sistema
            if ($this->isProtected($name) || $this->isInternalProvider($provider)) {
                $provider->boot($container);
            } else {
                ModuleGuard::safeBoot(fn() => $provider->boot($container), $name);
            }
        }
    }

    public function registerRoutes(RouterInterface $router): void
    {
        $resolver = new CapabilityResolver($this->storageDir());
        foreach ($this->providers as $name => $provider) {
            if (!$this->isEnabled($name)) continue;
            if (!$this->isProviderActive($provider, $resolver)) continue;

            // Módulos internos executam diretamente — módulos externos são isolados
            if ($this->isProtected($name) || $this->isInternalProvider($provider)) {
                $provider->registerRoutes(new ModuleScopedRouter($router, $this, $name));
            } else {
                ModuleGuard::safeLoadRoutes(
                    fn() => $provider->registerRoutes(new ModuleScopedRouter($router, $this, $name)),
                    $name
                );
            }
        }
    }

    /**
     * Verifica se um provider é interno (vive em src/Modules/).
     * Providers internos são confiáveis e não precisam de isolamento.
     */
    private function isInternalProvider(ModuleProviderInterface $provider): bool
    {
        if (!($provider instanceof SimpleModuleProvider)) return false;
        $path = realpath($provider->getPath());
        if ($path === false) return false;
        $sep = DIRECTORY_SEPARATOR;
        return str_contains($path, "{$sep}src{$sep}Modules{$sep}");
    }

    public function providers(): array
    {
        return $this->providers;
    }

    public function isEnabled(string $module): bool
    {
        if (array_key_exists($module, $this->enabled)) {
            return $this->enabled[$module];
        }
        // Módulo não descoberto ainda — lê o state persistido para não assumir true
        $persisted = $this->loadState();
        if (array_key_exists($module, $persisted)) {
            return $persisted[$module];
        }
        return true; // nunca visto antes = habilitado por padrão
    }

    public function isProtected(string $module): bool
    {
        return in_array($module, $this->protectedModules, true);
    }

    public function setEnabled(string $module, bool $enabled): void
    {
        // Permite registrar estado de módulos recém-criados que ainda não foram descobertos
        // (ex: createProject chama setEnabled antes do próximo boot/discover)
        if (!isset($this->providers[$module]) && !array_key_exists($module, $this->enabled)) {
            if (!$enabled) {
                // Só persiste se for false — false de um módulo novo precisa ser salvo
                $this->enabled[$module] = false;
                $this->persistState();
            }
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
        foreach ($this->providers as $name => $provider) {
            $desc = $provider->describe();
            $modules[] = [
                'name'        => $name,
                'enabled'     => $this->isEnabled($name),
                'protected'   => $this->isProtected($name),
                'description' => $desc['description'] ?? '',
                'routes'      => $desc['routes'] ?? [],
            ];
        }
        return $modules;
    }

    /**
     * Verifica se o caminho de módulos é o diretório interno do kernel (src/Modules/).
     * Módulos internos são confiáveis e não passam pela validação do ModuleGuard.
     * Apenas módulos externos (vendor/, storage/modules/) são validados.
     */
    private function isInternalModulesPath(string $modulesPath): bool
    {
        $root = dirname(__DIR__, 3);
        $internalPath = realpath($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules');
        $realModulesPath = realpath($modulesPath);
        return $internalPath !== false
            && $realModulesPath !== false
            && $internalPath === $realModulesPath;
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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fp = fopen($this->cacheFile, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $json);
        flock($fp, LOCK_UN);
        fclose($fp);
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
        $json = json_encode($this->enabled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fp = fopen($this->stateFile, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $json);
        flock($fp, LOCK_UN);
        fclose($fp);
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

            if ($active === null) {
                $resolver->setProvider($cap, $pluginId);
                return true;
            }

            if (strcasecmp($active, $pluginId) !== 0) {
                return false;
            }
        }
        return true;
    }

    private function providerProvides(ModuleProviderInterface $provider): array
    {
        $class = get_class($provider);
        if (isset($this->providesCache[$class])) {
            return $this->providesCache[$class];
        }

        $ref  = new \ReflectionClass($provider);
        $file = $ref->getFileName() ?: '';
        if (empty($file)) {
            return $this->providesCache[$class] = [];
        }

        $dir = dirname($file);

        for ($i = 0; $i < 4; $i++) {
            if (empty($dir) || $dir === '.' || $dir === '/' || $dir === '\\') break;

            $composer = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($composer)) {
                $raw = file_get_contents($composer);
                if ($raw !== false) {
                    $data     = json_decode($raw, true);
                    $provides = $data['extra']['vupi.us']['provides'] ?? [];
                    if (!empty($provides)) {
                        return $this->providesCache[$class] = $provides;
                    }
                }
            }

            $plugin = $dir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (is_file($plugin)) {
                $raw = file_get_contents($plugin);
                if ($raw !== false) {
                    $data     = json_decode($raw, true);
                    $provides = $data['provides'] ?? [];
                    if (!empty($provides)) {
                        return $this->providesCache[$class] = $provides;
                    }
                }
            }

            $dir = dirname($dir);
        }

        return $this->providesCache[$class] = [];
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
