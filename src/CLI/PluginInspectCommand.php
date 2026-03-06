<?php
namespace Src\CLI;

class PluginInspectCommand
{
    public function handle(): void
    {
        $root = dirname(__DIR__, 2);
        $plugins = [];
        $this->scanDirPlugins($plugins, $root . DIRECTORY_SEPARATOR . 'plugins');
        $this->scanVendorPlugins($plugins, $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow');
        $capProviders = $this->buildCapabilityProviders($plugins);
        $this->printReport($plugins, $capProviders);
    }

    private function scanDirPlugins(array &$plugins, string $base): void
    {
        if (!is_dir($base)) {
            return;
        }
        foreach (scandir($base) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $p = $base . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($p)) continue;
            $pluginJson = $p . DIRECTORY_SEPARATOR . 'plugin.json';
            $name = $dir;
            $provides = [];
            $requires = [];
            if (is_file($pluginJson)) {
                $data = json_decode(@file_get_contents($pluginJson), true) ?: [];
                $name = $data['name'] ?? $dir;
                $provides = array_values(array_filter((array)($data['provides'] ?? [])));
                $requires = array_values(array_filter((array)($data['requires'] ?? [])));
            }
            $plugins[$name] = [
                'name' => $name,
                'path' => $p,
                'provides' => $provides,
                'requires' => $requires,
                'source' => 'plugins',
            ];
        }
    }

    private function scanVendorPlugins(array &$plugins, string $vendorSweflow): void
    {
        if (!is_dir($vendorSweflow)) {
            return;
        }
        foreach (scandir($vendorSweflow) as $pkg) {
            if ($pkg === '.' || $pkg === '..') continue;
            $pkgDir = $vendorSweflow . DIRECTORY_SEPARATOR . $pkg;
            if (!is_dir($pkgDir)) continue;
            $pluginJson = $pkgDir . DIRECTORY_SEPARATOR . 'plugin.json';
            $name = $pkg;
            $provides = [];
            $requires = [];
            if (is_file($pluginJson)) {
                $data = json_decode(@file_get_contents($pluginJson), true) ?: [];
                $name = $data['name'] ?? $pkg;
                $provides = array_values(array_filter((array)($data['provides'] ?? [])));
                $requires = array_values(array_filter((array)($data['requires'] ?? [])));
            }
            $plugins[$name] = [
                'name' => $name,
                'path' => $pkgDir,
                'provides' => $provides,
                'requires' => $requires,
                'source' => 'vendor',
            ];
        }
    }

    private function buildCapabilityProviders(array $plugins): array
    {
        $map = [];
        foreach ($plugins as $plug) {
            foreach ($plug['provides'] as $cap) {
                $map[$cap][] = $plug['name'];
            }
        }
        foreach ($map as $cap => $list) {
            $map[$cap] = array_values(array_unique($list));
            sort($map[$cap], SORT_NATURAL | SORT_FLAG_CASE);
        }
        return $map;
    }

    private function printReport(array $plugins, array $capProviders): void
    {
        echo "Installed Plugins\n\n";
        $names = array_keys($plugins);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $maxLen = 0;
        foreach ($names as $n) {
            $maxLen = max($maxLen, strlen($n));
        }
        foreach ($names as $n) {
            $p = $plugins[$n];
            $left = str_pad($p['name'], $maxLen, ' ', STR_PAD_RIGHT);
            if (!empty($p['provides'])) {
                echo $left . "  provides: " . implode(', ', $p['provides']) . "\n";
            } elseif (!empty($p['requires'])) {
                echo $left . "  requires: " . implode(', ', $p['requires']) . "\n";
            } else {
                echo $left . "\n";
            }
        }
        echo "\nStatus\n\n";
        foreach ($names as $n) {
            $p = $plugins[$n];
            if (empty($p['requires'])) continue;
            foreach ($p['requires'] as $cap) {
                $providers = $capProviders[$cap] ?? [];
                if (empty($providers)) {
                    echo $p['name'] . " -> missing capability " . $cap . "\n";
                } elseif (count($providers) === 1) {
                    echo $p['name'] . " -> resolved via " . $providers[0] . "\n";
                } else {
                    echo $p['name'] . " -> multiple providers for " . $cap . " [" . implode(', ', $providers) . "]\n";
                }
            }
        }
        if (empty($capProviders)) {
            echo "No capabilities provided\n";
        }
    }
}
