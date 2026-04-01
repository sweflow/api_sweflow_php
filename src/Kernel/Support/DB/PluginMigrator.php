<?php
namespace Src\Kernel\Support\DB;

use PDO;

class PluginMigrator
{
    private PDO $pdo;
    private string $projectRoot;
    private string $table = 'sweflow_plugin_migrations';

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = $projectRoot;
        $this->ensureTable();
    }

    public function migrateAll(): void
    {
        foreach ($this->discoverPlugins() as $plugin) {
            $this->migratePlugin($plugin);
        }
    }

    public function seedAll(): void
    {
        foreach ($this->discoverPlugins() as $plugin) {
            $this->seedPlugin($plugin);
        }
    }

    public function rollbackLatest(?string $pluginName = null): void
    {
        $filter = $pluginName ? ' WHERE plugin = :p ' : '';
        $sql = "SELECT * FROM {$this->table}{$filter} ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        if ($pluginName) $stmt->bindValue(':p', $pluginName);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "Nenhuma plugin-migration aplicada\n";
            return;
        }
        $pluginPath = $this->resolvePluginPath($row['plugin']);
        if (!$pluginPath) {
            $this->deleteRow((int)$row['id']);
            echo "Registro órfão removido: {$row['plugin']} {$row['migration']}\n";
            return;
        }
        $file = $pluginPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . $row['version'] . DIRECTORY_SEPARATOR . $row['migration'] . '.php';
        if (is_file($file)) {
            $callable = include $file;
            if (is_array($callable) && isset($callable['down']) && is_callable($callable['down'])) {
                ($callable['down'])($this->pdo);
            }
        }
        $this->deleteRow((int)$row['id']);
        echo "✔ rollback(plugin): {$row['plugin']} {$row['migration']}\n";
    }

    private function migratePlugin(array $plugin): void
    {
        $migrationsRoot = $this->getMigrationsRootPath($plugin['path']);
        $filesToMigrate = [];

        if ($this->directoryExists($migrationsRoot)) {
            $versions = $this->getVersionDirectories($migrationsRoot);
            $filesToMigrate = $this->collectMigrationFiles($migrationsRoot, $versions, $plugin['version']);
        }

        foreach ($filesToMigrate as $file) {
            $this->runMigrationFile($file);
        }
    }

    private function getMigrationsRootPath(string $pluginPath): string
    {
        return $pluginPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
    }

    private function directoryExists(string $path): bool
    {
        return is_dir($path);
    }

    private function getVersionDirectories(string $migrationsRoot): array
    {
        $versions = [];
        foreach (scandir($migrationsRoot) as $ver) {
            if ($ver === '.' || $ver === '..') {
                continue;
            }
            $verDir = $migrationsRoot . DIRECTORY_SEPARATOR . $ver;
            if (is_dir($verDir)) {
                $versions[] = $ver;
            }
        }
        natsort($versions);
        return $versions;
    }

    private function collectMigrationFiles(string $migrationsRoot, array $versions, string $maxVersion): array
    {
        $files = [];
        foreach ($versions as $ver) {
            if ($this->compareVersions($ver, $maxVersion) > 0) {
                break;
            }
            $pattern = $migrationsRoot . DIRECTORY_SEPARATOR . $ver . DIRECTORY_SEPARATOR . '*.php';
            $found = glob($pattern) ?: [];
            $files = array_merge($files, $found);
        }
        return $files;
    }

    private function runMigrationFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $callable = include $file;
        if (is_array($callable) && isset($callable['up']) && is_callable($callable['up'])) {
            $callable['up']($this->pdo);
        }
    }
            sort($files, SORT_NATURAL);
            foreach ($files as $file) {
                $nameOnly = basename($file, '.php');
                if ($this->isApplied($name, $ver, $nameOnly)) {
                    continue;
                }
                $callable = include $file;
                if (is_array($callable) && isset($callable['up']) && is_callable($callable['up'])) {
                    ($callable['up'])($this->pdo);
                } elseif (is_callable($callable)) {
                    $callable($this->pdo);
                } else {
                    continue;
                }
                $this->markApplied($name, $ver, $nameOnly);
                echo "✔ plugin: {$name} {$ver} {$nameOnly}\n";
            }
        }

        // Flat fallback: src/Database/Migrations/*.php
        $flat = glob($migrationsRoot . DIRECTORY_SEPARATOR . '*.php') ?: [];
        if ($flat) {
            sort($flat, SORT_NATURAL);
            foreach ($flat as $file) {
                $nameOnly = basename($file, '.php');
                if ($this->isApplied($name, $version, $nameOnly)) {
                    continue;
                }
                $callable = include $file;
                if (is_array($callable) && isset($callable['up']) && is_callable($callable['up'])) {
                    ($callable['up'])($this->pdo);
                } elseif (is_callable($callable)) {
                    $callable($this->pdo);
                } else {
                    continue;
                }
                $this->markApplied($name, $version, $nameOnly);
                echo "✔ plugin: {$name} {$version} {$nameOnly}\n";
            }
        }
    }

    private function seedPlugin(array $plugin): void
    {
        $seedersRoot = $plugin['path'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
        if (!is_dir($seedersRoot)) {
            return;
        }
        $files = glob($seedersRoot . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);
        foreach ($files as $file) {
            $callable = include $file;
            if (is_callable($callable)) {
                $callable($this->pdo);
                echo "✔ plugin seed: {$plugin['name']}:" . basename($file, '.php') . "\n";
            }
        }
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id SERIAL PRIMARY KEY,
                plugin VARCHAR(255) NOT NULL,
                version VARCHAR(50) NOT NULL,
                migration VARCHAR(255) NOT NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT NOW()
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plugin VARCHAR(255) NOT NULL,
                version VARCHAR(50) NOT NULL,
                migration VARCHAR(255) NOT NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )";
        }
        $this->pdo->exec($sql);
    }

    private function isApplied(string $plugin, string $version, string $migration): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE plugin = :p AND version = :v AND migration = :m");
        $stmt->bindValue(':p', $plugin);
        $stmt->bindValue(':v', $version);
        $stmt->bindValue(':m', $migration);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    private function markApplied(string $plugin, string $version, string $migration): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (plugin, version, migration) VALUES (:p, :v, :m)");
        $stmt->bindValue(':p', $plugin);
        $stmt->bindValue(':v', $version);
        $stmt->bindValue(':m', $migration);
        $stmt->execute();
    }

    private function deleteRow(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function discoverPlugins(): array
    {
        $list = [];
        // Local plugins
        $local = $this->projectRoot . DIRECTORY_SEPARATOR . 'plugins';
        $this->collectPluginsFrom($local, $list, 'plugins');
        // Vendor plugins
        $vendor = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow';
        $this->collectPluginsFrom($vendor, $list, 'vendor');
        return array_values($list);
    }

    private function collectPluginsFrom(string $root, array &$list, string $source): void
    {
        if (!is_dir($root)) return;
        foreach (scandir($root) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $path = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) continue;
            $plugin = $this->readPluginMeta($path);
            if (!$plugin) continue;
            $key = $plugin['name'];
            // Keep first occurrence (prefer local over vendor)
            if (!isset($list[$key]) || $source === 'plugins') {
                $list[$key] = $plugin + ['path' => $path, 'source' => $source];
            }
        }
    }

    private function readPluginMeta(string $path): ?array
    {
        $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
        $version = '1.0.0';
        $name = basename($path);
        if (is_file($pluginJson)) {
            $data = json_decode(@file_get_contents($pluginJson), true) ?: [];
            $name = $data['name'] ?? $name;
            $version = $data['version'] ?? $version;
        } else {
            // Fallback: read composer.json name field
            $composer = $path . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($composer)) {
                $meta = json_decode(@file_get_contents($composer), true) ?: [];
                $name = $meta['name'] ?? $name;
            }
        }
        return ['name' => $name, 'version' => $version];
    }

    private function resolvePluginPath(string $pluginName): ?string
    {
        $candidates = [
            $this->projectRoot . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . basename($pluginName),
            $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow' . DIRECTORY_SEPARATOR . basename($pluginName),
        ];
        foreach ($candidates as $p) {
            if (is_dir($p)) return $p;
        }
        return null;
    }

    private function compareVersions(string $a, string $b): int
    {
        if ($a === $b) return 0;
        // naive compare that works ok with semver dot-separated
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));
        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $xa = $pa[$i] ?? 0;
            $xb = $pb[$i] ?? 0;
            if ($xa === $xb) continue;
            return $xa <=> $xb;
        }
        return 0;
    }
}
