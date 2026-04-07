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
            mkdir(dirname($this->registry), 0755, true);
        }
        if (!is_file($this->registry)) {
            file_put_contents($this->registry, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function install(string $pluginName): void
    {
        $state = $this->read();
        $state[$pluginName] = ['enabled' => true, 'installed_at' => date('c')];
        $this->write($state);
        // Garante que o modules_state.json marca o módulo como habilitado (limpa false de desinstalação anterior)
        $this->setModulesState($pluginName, true);
        $this->migrator->migrateAll();
        $this->callLifecycle($pluginName, 'onInstall');
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
        
        // 2. Resolve path BEFORE unregistering
        $path = $this->resolvePluginPath($pluginName);

        // 3. Remove from plugins registry
        $state = $this->read();
        unset($state[$pluginName]);
        $this->write($state);

        // 4. Remove from modules_state.json so ModuleLoader stops loading it
        $this->removeFromModulesState($pluginName);

        // 5. Delete files — handles src/Modules/, plugins/, and vendor symlinks/junctions
        if ($path) {
            $realPath = realpath($path) ?: $path;
            $inPlugins = str_contains($realPath, 'plugins' . DIRECTORY_SEPARATOR);
            $inModules = str_contains($realPath, 'src' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR);
            $inVendor  = str_contains($realPath, 'vendor' . DIRECTORY_SEPARATOR);

            $base = basename($realPath);
            $safe = !in_array($base, ['src', 'Modules', 'plugins', 'vendor', 'sweflow'], true);

            if ($safe && ($inPlugins || $inModules || $inVendor)) {
                $this->deleteDirectory($path); // use original $path to handle junctions
            }
        }
    }

    private function setModulesState(string $pluginName, bool $enabled): void
    {
        $stateFile = dirname($this->registry) . DIRECTORY_SEPARATOR . 'modules_state.json';
        $data = [];
        if (is_file($stateFile)) {
            $fp = fopen($stateFile, 'r');
            if ($fp) {
                flock($fp, LOCK_SH);
                $raw = stream_get_contents($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
                $data = json_decode((string) $raw, true) ?? [];
            }
        }
        foreach ([ucfirst($pluginName), strtolower($pluginName), $pluginName] as $v) {
            unset($data[$v]);
        }
        $data[ucfirst($pluginName)] = $enabled;
        $this->writeJsonFile($stateFile, $data);
    }

    private function removeFromModulesState(string $pluginName): void
    {
        $stateFile = dirname($this->registry) . DIRECTORY_SEPARATOR . 'modules_state.json';
        if (!is_file($stateFile)) {
            return;
        }
        $fp = fopen($stateFile, 'r');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return;
        }

        $variants = [$pluginName, ucfirst($pluginName), strtolower($pluginName)];
        $changed  = false;
        foreach ($variants as $v) {
            if (array_key_exists($v, $data)) {
                $data[$v] = false;
                $changed  = true;
            }
        }
        if (!$changed) {
            $data[ucfirst($pluginName)] = false;
        }

        $this->writeJsonFile($stateFile, $data);
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        // Se for link simbólico, deleta o link sem seguir (Linux/Windows)
        if (is_link($dir)) {
            return unlink($dir);
        }

        if (!is_dir($dir)) {
            // Arquivo normal
            if (unlink($dir)) {
                return true;
            }
            // Tenta forçar permissão (Linux/Windows)
            chmod($dir, 0666);
            return unlink($dir);
        }

        // Diretório
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            $itemPath = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (!$this->deleteDirectory($itemPath)) {
                chmod($itemPath, 0777);
                /** @phpstan-ignore-next-line */
                if (!$this->deleteDirectory($itemPath)) {
                    return false;
                }
            }
        }

        if (!rmdir($dir)) {
            chmod($dir, 0777);
            return rmdir($dir);
        }
        
        return true;
    }

    /** Public wrapper so controllers can delete arbitrary directories safely. */
    public function deleteDirectoryPublic(string $dir): bool
    {
        return $this->deleteDirectory($dir);
    }

    public function read(): array
    {
        $json = file_get_contents($this->registry);
        $data = $json ? json_decode($json, true) : [];
        return is_array($data) ? $data : [];
    }

    private function write(array $state): void
    {
        $this->writeJsonFile($this->registry, $state);
    }

    /**
     * Escreve JSON em arquivo com lock exclusivo para evitar race conditions.
     */
    private function writeJsonFile(string $path, array $data): void
    {
        $fp = fopen($path, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
        fclose($fp);
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
        $data = json_decode(file_get_contents($pj), true) ?: [];
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

        $meta = json_decode(file_get_contents($composer), true) ?: [];
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
