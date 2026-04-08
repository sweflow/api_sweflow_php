<?php
namespace Src\Kernel\Support\DB;

use PDO;

class PluginMigrator
{
    private PDO $pdo;
    private string $projectRoot;
    private string $table = 'vupi_plugin_migrations';

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = $projectRoot;
        $this->ensureTable();
    }

    public function migrateAll(): void
    {
        $this->migrateKernel();
        foreach ($this->discoverPlugins() as $plugin) {
            $this->migratePlugin($plugin);
        }
    }

    /**
     * Roda apenas as migrations de plugins externos — sem o kernel.
     * Usado quando o kernel já foi rodado no banco core separadamente.
     */
    public function migratePluginsOnly(): void
    {
        foreach ($this->discoverPlugins() as $plugin) {
            $this->migratePlugin($plugin);
        }
    }

    public function discoverPluginsPublic(): array
    {
        return $this->discoverPlugins();
    }

    public function migratePluginPublic(array $plugin): void
    {
        $this->migratePlugin($plugin);
    }

    public function seedAll(): void
    {
        foreach ($this->discoverPlugins() as $plugin) {
            $this->seedPlugin($plugin);
        }
    }

    public function rollbackLatest(?string $pluginName = null): void
    {
        if ($pluginName !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE plugin = :p ORDER BY id DESC LIMIT 1");
            $stmt->bindValue(':p', $pluginName);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT 1");
        }
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

    /**
     * Roda as migrations SQL do kernel (src/Kernel/Database/migrations/*.sql).
     * Cada arquivo é executado uma única vez, rastreado na tabela vupi_plugin_migrations.
     */
    private function migrateKernel(): void
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
             . 'Kernel' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'migrations';

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $name = basename($file, '.sql');
            if ($this->isApplied('kernel', '1.0.0', $name)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $inTx   = false;
            try {
                // PostgreSQL suporta DDL em transação — garante atomicidade
                if ($driver === 'pgsql' && !$this->pdo->inTransaction()) {
                    $this->pdo->beginTransaction();
                    $inTx = true;
                }
                $this->pdo->exec($sql);
                $this->markApplied('kernel', '1.0.0', $name);
                if ($inTx) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($inTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log("[PluginMigrator] kernel migration '{$name}' failed: " . $e->getMessage());
            }
        }
    }

    private function migratePlugin(array $plugin): void
    {
        $migrationsRoot = $plugin['path'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrationsRoot)) {
            return;
        }

        $this->runVersionedMigrations($plugin['name'], $plugin['version'], $migrationsRoot);
        $this->runFlatMigrations($plugin['name'], $plugin['version'], $migrationsRoot);
    }

    private function runVersionedMigrations(string $name, string $version, string $migrationsRoot): void
    {
        foreach ($this->getAvailableVersions($migrationsRoot) as $ver) {
            if ($this->compareVersions($ver, $version) > 0) {
                break;
            }
            $files = $this->getMigrationFilesForVersion($migrationsRoot, $ver);
            sort($files, SORT_NATURAL);
            foreach ($files as $file) {
                $this->runMigrationFile($file, $name, $ver);
            }
        }
    }

    private function runFlatMigrations(string $name, string $version, string $migrationsRoot): void
    {
        $flat = glob($migrationsRoot . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($flat, SORT_NATURAL);
        foreach ($flat as $file) {
            $this->runMigrationFile($file, $name, $version);
        }
    }

    private function runMigrationFile(string $file, string $name, string $ver): void
    {
        $nameOnly = basename($file, '.php');
        if ($this->isApplied($name, $ver, $nameOnly)) {
            return;
        }

        // Garante que o arquivo está dentro do projectRoot — previne path traversal
        $realFile = realpath($file);
        $realRoot = realpath($this->projectRoot);
        if ($realFile === false || $realRoot === false
            || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            error_log("[PluginMigrator] Arquivo fora do projectRoot ignorado: {$file}");
            return;
        }

        $callable = include $realFile;
        if (is_array($callable) && isset($callable['up']) && is_callable($callable['up'])) {
            ($callable['up'])($this->pdo);
        } elseif (is_callable($callable)) {
            $callable($this->pdo);
        } else {
            return;
        }
        $this->markApplied($name, $ver, $nameOnly);
        echo "✔ plugin: {$name} {$ver} {$nameOnly}\n";
    }

    private function getAvailableVersions(string $migrationsRoot): array
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

    private function getMigrationFilesForVersion(string $migrationsRoot, string $version): array
    {
        return glob($migrationsRoot . DIRECTORY_SEPARATOR . $version . DIRECTORY_SEPARATOR . '*.php') ?: [];
    }

    private function seedPlugin(array $plugin): void
    {
        $seedersRoot = $plugin['path'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
        if (!is_dir($seedersRoot)) {
            return;
        }
        $realRoot = realpath($this->projectRoot);
        $files = glob($seedersRoot . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);
        foreach ($files as $file) {
            $realFile = realpath($file);
            if ($realFile === false || $realRoot === false
                || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $callable = include $realFile;
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
                executed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (plugin, version, migration)
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plugin VARCHAR(255) NOT NULL,
                version VARCHAR(50) NOT NULL,
                migration VARCHAR(255) NOT NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_plugin_migration (plugin, version, migration)
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
        // Local plugins (legacy)
        $local = $this->projectRoot . DIRECTORY_SEPARATOR . 'plugins';
        $this->collectPluginsFrom($local, $list, 'plugins');
        // Vendor plugins
        $vendor = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'vupi.us';
        $this->collectPluginsFrom($vendor, $list, 'vendor');
        // src/Modules — módulos instalados no padrão nativo
        $modules = $this->projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
        $this->collectPluginsFrom($modules, $list, 'modules');
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

    private function readPluginMeta(string $path): array
    {
        $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
        $version = '1.0.0';
        $name = basename($path);
        if (is_file($pluginJson)) {
            $raw = is_readable($pluginJson) ? file_get_contents($pluginJson) : false;
            $data = ($raw !== false) ? (json_decode($raw, true) ?: []) : [];
            $name = $data['name'] ?? $name;
            $version = $data['version'] ?? $version;
        } else {
            // Fallback: read composer.json name field
            $composer = $path . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($composer)) {
                $raw = is_readable($composer) ? file_get_contents($composer) : false;
                $meta = ($raw !== false) ? (json_decode($raw, true) ?: []) : [];
                $name = $meta['name'] ?? $name;
            }
        }
        return ['name' => $name, 'version' => $version];
    }

    private function resolvePluginPath(string $pluginName): ?string
    {
        $candidates = [
            $this->projectRoot . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . basename($pluginName),
            $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'vupi.us' . DIRECTORY_SEPARATOR . basename($pluginName),
        ];
        foreach ($candidates as $p) {
            if (is_dir($p)) return $p;
        }
        return null;
    }

    private function compareVersions(string $a, string $b): int
    {
        return version_compare($a, $b);
    }
}
