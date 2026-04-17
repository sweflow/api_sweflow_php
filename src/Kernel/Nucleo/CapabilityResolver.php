<?php
namespace Src\Kernel\Nucleo;

class CapabilityResolver
{
    private string $file;

    public function __construct(string $storageDir)
    {
        $this->file = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'capabilities_registry.json';
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
        $providers = [];
        foreach ($this->scanAllModuleDirs() as [$dir, $name]) {
            $provides = $this->readProvides($dir);
            if (in_array($capability, $provides, true)) {
                $providers[] = $name;
            }
        }
        $providers = array_values(array_unique($providers));
        sort($providers, SORT_NATURAL | SORT_FLAG_CASE);
        return $providers;
    }

    public function getAllCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->scanAllModuleDirs() as [$dir]) {
            foreach ($this->readProvides($dir) as $cap) {
                $capabilities[$cap] = true;
            }
        }
        return array_keys($capabilities);
    }

    /**
     * Retorna todas as capabilities: as declaradas via plugin.json
     * MAIS as que já estão salvas no registry (mesmo sem plugin.json).
     * Garante que providers salvos manualmente nunca desaparecem da UI.
     */
    public function getAllCapabilitiesIncludingSaved(): array
    {
        $capabilities = [];

        // 1. Capabilities declaradas via plugin.json
        foreach ($this->scanAllModuleDirs() as [$dir]) {
            foreach ($this->readProvides($dir) as $cap) {
                $capabilities[$cap] = true;
            }
        }

        // 2. Capabilities já salvas no registry (ex: email-sender salvo manualmente)
        foreach (array_keys($this->read()) as $cap) {
            $capabilities[$cap] = true;
        }

        return array_keys($capabilities);
    }

    /**
     * Varre todos os diretórios de módulos e retorna pares [path, name].
     * Centraliza a lógica duplicada entre listProviders() e getAllCapabilities().
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function scanAllModuleDirs(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $searchPaths = [
            $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules',
            $projectRoot . DIRECTORY_SEPARATOR . 'plugins',
            $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'vupi.us',
        ];

        $result = [];
        foreach ($searchPaths as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . $dir;
                if (is_dir($path)) {
                    $result[] = [$path, $dir];
                }
            }
        }
        return $result;
    }

    /**
     * Lê o array 'provides' de um diretório de módulo (plugin.json ou composer.json).
     */
    private function readProvides(string $path): array
    {
        $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
        if (is_file($pluginJson) && is_readable($pluginJson)) {
            $raw  = file_get_contents($pluginJson);
            $data = $raw !== false ? (json_decode($raw, true) ?: []) : [];
            $provides = $data['provides'] ?? [];
            if (is_array($provides)) {
                return $provides;
            }
        }

        $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerJson) && is_readable($composerJson)) {
            $raw  = file_get_contents($composerJson);
            $data = $raw !== false ? (json_decode($raw, true) ?: []) : [];
            $provides = $data['extra']['vupi.us']['provides'] ?? [];
            if (is_array($provides)) {
                return $provides;
            }
        }

        return [];
    }

    public function validate(): void
    {
        $map     = $this->read();
        $changed = false;

        foreach ($map as $cap => $provider) {
            $available = $this->listProviders($cap);

            // Se não há providers registrados via plugin.json, não remove —
            // o provider pode ter sido instalado sem plugin.json (ex: módulo Email)
            if (empty($available)) {
                continue;
            }

            if (in_array($provider, $available, true)) {
                continue; // provider still valid
            }

            // Provider inválido — remove e tenta auto-selecionar se há exatamente 1 alternativa
            unset($map[$cap]);
            $changed = true;

            if (count($available) === 1) {
                $map[$cap] = $available[0];
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
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($this->file, 'c+');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
