<?php

namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\Migrator;
use Src\Kernel\Support\DB\PluginMigrator;

/**
 * CLI: php sweflow migrate [opções]
 *
 * Opções:
 *   --seed              Executa seeders após migrations
 *   --rollback          Reverte a última migration (conexão core)
 *   --rollback --modules  Reverte a última migration (conexão modules)
 *   --core              Executa apenas módulos com conexão 'core'
 *   --modules           Executa apenas módulos com conexão 'modules'
 *   --status            Exibe status de todas as migrations
 *   --status --json     Retorna status em JSON (para CI/CD e dashboard)
 */
class MigrateCommand
{
    public function run(array $args): int
    {
        $seed        = in_array('--seed',     $args, true);
        $rollback    = in_array('--rollback', $args, true);
        $onlyCore    = in_array('--core',     $args, true);
        $onlyModules = in_array('--modules',  $args, true);
        $status      = in_array('--status',   $args, true);
        $asJson      = in_array('--json',     $args, true);

        // Suprime header quando saída é JSON puro
        if (!$asJson) {
            echo "\n\033[1;36m=== Sweflow Migrate ===\033[0m\n\n";
        }

        try {
            $pdo  = PdoFactory::fromEnv('DB');
            $root = dirname(__DIR__, 2);

            $pdoModules = PdoFactory::hasSecondaryConnection()
                ? PdoFactory::fromEnv('DB2')
                : $pdo;

            $migrator       = new Migrator($pdo, $root, $pdoModules);
            $pluginMigrator = new PluginMigrator($pdoModules, $root);

            // ── Status ────────────────────────────────────────────────────────
            if ($status) {
                if (!$asJson) {
                    echo "\033[1m[migrate:status]\033[0m\n";
                }
                $migrator->status($asJson);
                if (!$asJson) {
                    echo "\n";
                }
                return 0;
            }

            // ── Rollback ──────────────────────────────────────────────────────
            if ($rollback) {
                $conn = $onlyModules ? 'modules' : 'core';
                echo "\033[1mRevertendo última migration [{$conn}]...\033[0m\n";
                $migrator->rollback($conn);
                echo "\n\033[32m✓ Rollback concluído.\033[0m\n\n";
                return 0;
            }

            // ── Migrations ────────────────────────────────────────────────────

            // 1. Kernel — sempre no banco core
            if (!$onlyModules) {
                echo "\033[1m[1/3] Migrations do kernel [core]\033[0m\n";
                $this->runKernelMigrations($pdo);
            }

            // 2. Módulos — cada um usa sua conexão definida em connection.php
            $label = $onlyCore ? 'core' : ($onlyModules ? 'modules' : 'todas');
            echo "\n\033[1m[2/3] Migrations dos módulos [{$label}]\033[0m\n";

            if ($onlyCore) {
                $migrator->migrateCore();
            } elseif ($onlyModules) {
                $migrator->migrateModules();
            } else {
                $migrator->migrate();
            }

            // 3. Plugins em vendor/sweflow/ — usa conexão de módulos, sem rodar kernel SQL
            if (!$onlyCore) {
                $connLabel = PdoFactory::hasSecondaryConnection() ? 'DB2' : 'DB';
                echo "\n\033[1m[3/3] Migrations de plugins (vendor/sweflow/) [{$connLabel}]\033[0m\n";
                $pluginMigrator->migratePluginsOnly();
            }

            // 4. Seeders
            if ($seed) {
                echo "\n\033[1m[Seeders]\033[0m\n";
                if ($onlyCore) {
                    $migrator->seedCore();
                } elseif ($onlyModules) {
                    $migrator->seedModules();
                } else {
                    $migrator->seed();
                    $pluginMigrator->seedAll();
                }
            }

            echo "\n\033[32m✓ Concluído.\033[0m\n\n";
            return 0;

        } catch (\Throwable $e) {
            echo "\033[31m✗ Erro: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Executa os arquivos .sql em src/Kernel/Database/migrations/ — sempre no banco core.
     */
    private function runKernelMigrations(\PDO $pdo): void
    {
        $dir = dirname(__DIR__) . '/Kernel/Database/migrations';
        if (!is_dir($dir)) {
            echo "  (nenhuma migration de kernel encontrada)\n";
            return;
        }

        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        if (empty($files)) {
            echo "  (nenhuma migration de kernel encontrada)\n";
            return;
        }

        foreach ($files as $file) {
            $name = basename($file);

            try {
                $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m LIMIT 1");
                $stmt->execute([':m' => 'kernel/' . $name]);
                if ($stmt->fetchColumn()) {
                    echo "  ⊘ kernel/$name (já executada)\n";
                    continue;
                }
            } catch (\Throwable) {}

            $sql      = file_get_contents($file);
            if ($sql === false) continue;

            $hasError = false;
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if ($statement === '') continue;
                try {
                    $pdo->exec($statement . ';');
                } catch (\Throwable $e) {
                    $msg     = $e->getMessage();
                    $ignorar = str_contains($msg, 'already exists')
                        || $msg === 'SQLSTATE[HY000]: General error: ';
                    if (!$ignorar) {
                        echo "  ⚠ $name: $msg\n";
                        $hasError = true;
                    }
                }
            }

            if (!$hasError) {
                try {
                    $ins = $pdo->prepare("INSERT INTO migrations (module, migration) VALUES ('kernel', :m)");
                    $ins->execute([':m' => 'kernel/' . $name]);
                } catch (\Throwable) {}
                echo "  ✔ kernel/$name\n";
            }
        }
    }
}
