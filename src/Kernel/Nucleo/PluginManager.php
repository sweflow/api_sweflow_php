<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Support\DB\PluginMigrator;
use Src\Kernel\Nucleo\CapabilityResolver;

class PluginManager
{
    private string $registry;
    private PluginMigrator $migrator;

    public function __construct(PluginMigrator $migrator, string $storageDir)
    {
        $this->migrator = $migrator;
        $this->registry = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'plugins_registry.json';
        if (!is_dir(dirname($this->registry))) {
            @mkdir(dirname($this->registry), 0777, true);
        }
        if (!is_file($this->registry)) {
            @file_put_contents($this->registry, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function install(string $pluginName): void
    {
        $state = $this->read();
        $state[$pluginName] = ['enabled' => true, 'installed_at' => date('c')];
        $this->write($state);
        $this->migrator->migrateAll();
        $this->callLifecycle($pluginName, 'onInstall');
        // Set default providers for capabilities if not configured
        $this->ensureCapabilitiesDefault($pluginName);
    }

    public function enable(string $pluginName): void
    {
        $state = $this->read();
        $row = $state[$pluginName] ?? ['enabled' => false];
        $row['enabled'] = true;
        $row['updated_at'] = date('c');
        $state[$pluginName] = $row;
        $this->write($state);
        $this->callLifecycle($pluginName, 'onEnable');
    }

    public function disable(string $pluginName): void
    {
        $state = $this->read();
        $row = $state[$pluginName] ?? ['enabled' => false];
        $row['enabled'] = false;
        $row['updated_at'] = date('c');
        $state[$pluginName] = $row;
        $this->write($state);
        $this->callLifecycle($pluginName, 'onDisable');
    }

    public function uninstall(string $pluginName): void
    {
        // 1. Lifecycle hook
        $this->callLifecycle($pluginName, 'onUninstall');
        
        // 2. Resolve path BEFORE unregistering (otherwise we lose metadata if we depended on it, though resolvePluginPath is stateless)
        $path = $this->resolvePluginPath($pluginName);

        // 3. Remove from registry
        // NOTE: For safety we DO NOT drop tables automatically here.
        $state = $this->read();
        unset($state[$pluginName]);
        $this->write($state);

        // 4. Delete files IF it is a local plugin (inside plugins/ or src/Modules/)
        // We do NOT delete vendor packages automatically as Composer manages them.
        if ($path) {
            $realPath = realpath($path);
            $inPlugins = str_contains($realPath, 'plugins' . DIRECTORY_SEPARATOR);
            $inModules = str_contains($realPath, 'src' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR);
            
            if ($inPlugins || $inModules) {
                // Double check to avoid deleting critical system folders by accident
                if (basename($realPath) !== 'src' && basename($realPath) !== 'Modules' && basename($realPath) !== 'plugins') {
                    $this->deleteDirectory($path);
                }
            }
        }
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        // Se for link simbólico, deleta o link sem seguir (Linux/Windows)
        if (is_link($dir)) {
            return @unlink($dir);
        }

        if (!is_dir($dir)) {
            // Arquivo normal
            if (@unlink($dir)) {
                return true;
            }
            // Tenta forçar permissão (Linux/Windows)
            @chmod($dir, 0666);
            return @unlink($dir);
        }

        // Diretório
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            $itemPath = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (!$this->deleteDirectory($itemPath)) {
                // Tenta mudar permissão recursiva e deletar novamente
                @chmod($itemPath, 0777);
                if (!$this->deleteDirectory($itemPath)) {
                    return false;
                }
            }
        }

        if (!@rmdir($dir)) {
            @chmod($dir, 0777);
            return @rmdir($dir);
        }
        
        return true;
    }

    public function read(): array
    {
        $json = @file_get_contents($this->registry);
        $data = $json ? json_decode($json, true) : [];
        return is_array($data) ? $data : [];
    }

    private function write(array $state): void
    {
        @file_put_contents($this->registry, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function callLifecycle(string $pluginName, string $method): void
    {
        $provider = $this->resolveProvider($pluginName);
        if ($provider && method_exists($provider, $method)) {
            $provider->{$method}();
        }
    }

    private function ensureCapabilitiesDefault(string $pluginName): void
    {
        $pluginPath = $this->resolvePluginPath($pluginName);
        if (!$pluginPath) return;
        $pj = $pluginPath . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($pj)) return;
        $data = json_decode(@file_get_contents($pj), true) ?: [];
        $provides = $data['provides'] ?? [];
        if (!is_array($provides) || empty($provides)) return;
        $resolver = new CapabilityResolver(dirname($this->registry));
        foreach ($provides as $cap) {
            if ($resolver->resolve($cap) === null) {
                $resolver->setProvider($cap, basename($pluginPath));
            }
        }
    }

    private function resolvePluginPath(string $pluginName): ?string
    {
        $projectRoot = dirname(__DIR__, 3);
        
        // Normalize name to handle "email", "module-email", "sweflow-module-email", "sweflow/module-email"
        // Also handle "sweflow/module-email" -> "email"
        $simpleName = $pluginName;
        if (str_contains($simpleName, '/')) {
            $parts = explode('/', $simpleName);
            $simpleName = end($parts); // "module-email"
        }
        $simpleName = str_replace(['sweflow-module-', 'module-'], '', $simpleName); // "email"
        
        // Capitalize for Modules (Email)
        $moduleName = ucfirst($simpleName);
        
        $candidates = [
            // 1. Native Modules (src/Modules/Email)
            $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $moduleName,
            // 2. Local dev path (plugins/sweflow-module-email) - Legacy/Dev
            $projectRoot . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'sweflow-module-' . $simpleName,
            // 3. Local dev path (plugins/email)
            $projectRoot . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $simpleName, // Usar simpleName aqui também
            // 4. Vendor path
            $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow' . DIRECTORY_SEPARATOR . 'module-' . $simpleName,
        ];

        foreach ($candidates as $p) {
            if (is_dir($p)) return $p;
        }
        return null;
    }

    private function resolveProvider(string $pluginName): ?ModuleProviderInterface
    {
        $path = $this->resolvePluginPath($pluginName);
        if (!$path) return null;

        $composer = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composer)) return null;

        $meta = json_decode(@file_get_contents($composer), true) ?: [];
        $providers = $meta['extra']['sweflow']['providers'] ?? [];

        if (!is_array($providers)) return null;

        foreach ($providers as $fqcn) {
            if (class_exists($fqcn)) {
                $prov = new $fqcn();
                if ($prov instanceof ModuleProviderInterface) {
                    return $prov;
                }
            }
        }
        return null;
    }
}
