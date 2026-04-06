<?php
namespace Src\Kernel\Nucleo;

class CapabilityResolver
{
    private string $file;

    public function __construct(string $storageDir)
    {
        $this->file = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'capabilities_registry.json';
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0755, true);
        }
        if (!is_file($this->file)) {
            file_put_contents($this->file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function resolve(string $capability): ?string
    {
        $map = $this->read();
        return $map[$capability] ?? null;
    }

    public function setProvider(string $capability, string $plugin): void
    {
        $map = $this->read();
        $map[$capability] = $plugin;
        $this->write($map);
    }

    public function removeProvider(string $capability): void
    {
        $map = $this->read();
        if (isset($map[$capability])) {
            unset($map[$capability]);
            $this->write($map);
        }
    }

    public function listProviders(string $capability): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $providers = [];
        $searchPaths = [
            $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules',
            $projectRoot . DIRECTORY_SEPARATOR . 'plugins',
            $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow'
        ];

        foreach ($searchPaths as $root) {
            if (!is_dir($root)) continue;
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $path = $root . DIRECTORY_SEPARATOR . $dir;
                if (!is_dir($path)) continue;
                
                // Support plugin.json (legacy/standard)
                $provides = [];
                $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
                
                if (is_file($pluginJson)) {
                    $raw = file_get_contents($pluginJson);
                    $pj = $raw ? (json_decode($raw, true) ?: []) : [];
                    $provides = $pj['provides'] ?? [];
                } else {
                    // Support composer.json (modern modules)
                    $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';
                    if (is_file($composerJson)) {
                        $raw = file_get_contents($composerJson);
                        $cj = $raw ? (json_decode($raw, true) ?: []) : [];
                        $provides = $cj['extra']['sweflow']['provides'] ?? [];
                    }
                }

                if (is_array($provides) && in_array($capability, $provides, true)) {
                    // Use folder name as provider ID, or 'name' from json if preferred?
                    // Existing code uses basename($path). Let's stick to that for consistency.
                    $providers[] = basename($path);
                }
            }
        }
        $providers = array_values(array_unique($providers));
        sort($providers, SORT_NATURAL | SORT_FLAG_CASE);
        return $providers;
    }

    public function getAllCapabilities(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $capabilities = [];
        $searchPaths = [
            $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules',
            $projectRoot . DIRECTORY_SEPARATOR . 'plugins',
            $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow'
        ];

        foreach ($searchPaths as $root) {
            if (!is_dir($root)) continue;
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $path = $root . DIRECTORY_SEPARATOR . $dir;
                if (!is_dir($path)) continue;

                $provides = [];
                $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
                if (is_file($pluginJson)) {
                    $raw = file_get_contents($pluginJson);
                    $pj = $raw ? (json_decode($raw, true) ?: []) : [];
                    $provides = $pj['provides'] ?? [];
                } else {
                    $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';
                    if (is_file($composerJson)) {
                        $raw = file_get_contents($composerJson);
                        $cj = $raw ? (json_decode($raw, true) ?: []) : [];
                        $provides = $cj['extra']['sweflow']['provides'] ?? [];
                    }
                }

                if (is_array($provides)) {
                    foreach ($provides as $cap) {
                        $capabilities[$cap] = true;
                    }
                }
            }
        }
        return array_keys($capabilities);
    }

    public function validate(): void
    {
        $map = $this->read();
        $changed = false;

        foreach ($map as $cap => $provider) {
            // Check if this provider actually provides this capability
            $available = $this->listProviders($cap);
            if (!in_array($provider, $available)) {
                // Autocorrect: Remove invalid provider
                unset($map[$cap]);
                $changed = true;
                
                // Optional: Auto-select if there is exactly one alternative?
                // For now, just removing is safer and meets "autocorreção" requirement (fixing invalid state).
                if (count($available) === 1) {
                    $map[$cap] = $available[0];
                }
            }
        }

        if ($changed) {
            $this->write($map);
        }
    }

    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $fp = fopen($this->file, 'r');
        if (!$fp) {
            return [];
        }
        flock($fp, LOCK_SH);
        $json = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = $json ? json_decode($json, true) : [];
        return is_array($data) ? $data : [];
    }

    private function write(array $map): void
    {
        $fp = fopen($this->file, 'c+');
        if (!$fp) {
            return;
        }

        // Tenta LOCK_NB (non-blocking) com retry para evitar deadlock
        // Se outro processo estiver escrevendo, aguarda até 500ms antes de desistir
        $locked  = false;
        $retries = 5;
        while ($retries-- > 0) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(100_000); // 100ms
        }

        if (!$locked) {
            // Não conseguiu o lock — descarta a escrita para não corromper o arquivo
            fclose($fp);
            return;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
