<?php
namespace Src\Kernel\Support\DB;

use PDO;

class Migrator
{
    private PDO $pdo;
    private string $projectRoot;

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = $projectRoot;
        $this->ensureMigrationsTable();
    }

    public function migrate(): void
    {
        $modules = $this->discoverModules();
        foreach ($modules as $module) {
            $dir = $module . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_NATURAL);
            foreach ($files as $file) {
                $name = basename($file, '.php');
                if ($this->isExecuted($module, $name)) {
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
                $this->markExecuted($module, $name);
                echo "✔ $name\n";
            }
        }
    }

    public function seed(): void
    {
        $modules = $this->discoverModules();
        foreach ($modules as $module) {
            $dir = $module . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_NATURAL);
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $key  = basename($module) . '/seeders/' . $name;
                if ($this->isSeederExecuted($key)) {
                    continue;
                }
                $callable = include $file;
                if (is_callable($callable)) {
                    $callable($this->pdo);
                } else {
                    continue;
                }
                $this->markSeederExecuted($key, basename($module));
                echo "✔ seeder: $name\n";
            }
        }
    }

    public function rollback(): void
    {
        $row = $this->lastMigration();
        if (!$row) {
            echo "Nenhuma migration aplicada\n";
            return;
        }
        $moduleDir = $this->moduleDir($row['module']);
        $file = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . $row['migration'] . '.php';
        if (is_file($file)) {
            $callable = include $file;
            if (is_array($callable) && isset($callable['down']) && is_callable($callable['down'])) {
                ($callable['down'])($this->pdo);
            } else {
                echo "Rollback sem down, removendo registro\n";
            }
        }
        $this->deleteMigration((int)$row['id']);
        echo "✔ Rollback: {$row['migration']}\n";
    }
    private function discoverModules(): array
    {
        $modules = [];

        $modulesRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
        if (is_dir($modulesRoot)) {
            $modules = array_merge($modules, $this->getDirectories($modulesRoot));
        }

        $vendorRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow';
        if (is_dir($vendorRoot)) {
            foreach ($this->getDirectories($vendorRoot) as $pkgDir) {
                $vModulesPath = $pkgDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
                if (is_dir($vModulesPath)) {
                    $modules = array_merge($modules, $this->getDirectories($vModulesPath));
                }
            }
        }

        return $modules;
    }

    private function getDirectories(string $path): array
    {
        $items = [];
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $items[] = $fullPath;
            }
        }
        return $items;
    }

    private function moduleDir(string $moduleName): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $moduleName;
    }

    private function ensureMigrationsTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                module VARCHAR(255) NOT NULL,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT NOW()
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(255) NOT NULL,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )";
        }
        $this->pdo->exec($sql);
    }

    private function isExecuted(string $modulePath, string $migrationName): bool
    {
        $module = basename($modulePath);
        $stmt = $this->pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
        $stmt->bindValue(':m', $module . '/' . $migrationName);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    private function markExecuted(string $modulePath, string $migrationName): void
    {
        $module = basename($modulePath);
        $stmt = $this->pdo->prepare("INSERT INTO migrations (module, migration) VALUES (:module, :migration)");
        $stmt->bindValue(':module', $module);
        $stmt->bindValue(':migration', $module . '/' . $migrationName);
        $stmt->execute();
    }

    private function lastMigration(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM migrations ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deleteMigration(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM migrations WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function isSeederExecuted(string $key): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
        $stmt->bindValue(':m', $key);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    private function markSeederExecuted(string $key, string $module): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO migrations (module, migration) VALUES (:module, :migration)");
        $stmt->bindValue(':module', $module);
        $stmt->bindValue(':migration', $key);
        $stmt->execute();
    }
}
