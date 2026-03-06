<?php
namespace Src\Kernel\Nucleo;

class CapabilityResolver
{
    private string $file;

    public function __construct(string $storageDir)
    {
        $this->file = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'capabilities_registry.json';
        if (!is_dir(dirname($this->file))) {
            @mkdir(dirname($this->file), 0777, true);
        }
        if (!is_file($this->file)) {
            @file_put_contents($this->file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    public function listProviders(string $capability): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $providers = [];
        foreach ([$projectRoot . DIRECTORY_SEPARATOR . 'plugins', $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow'] as $root) {
            if (!is_dir($root)) continue;
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $path = $root . DIRECTORY_SEPARATOR . $dir;
                if (!is_dir($path)) continue;
                $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
                if (!is_file($pluginJson)) continue;
                $pj = json_decode(@file_get_contents($pluginJson), true) ?: [];
                $provides = $pj['provides'] ?? [];
                if (is_array($provides) && in_array($capability, $provides, true)) {
                    $providers[] = basename($path);
                }
            }
        }
        $providers = array_values(array_unique($providers));
        sort($providers, SORT_NATURAL | SORT_FLAG_CASE);
        return $providers;
    }

    private function read(): array
    {
        $json = @file_get_contents($this->file);
        $data = $json ? json_decode($json, true) : [];
        return is_array($data) ? $data : [];
    }

    private function write(array $map): void
    {
        @file_put_contents($this->file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
