<?php

namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\Migrator;
use Src\Kernel\Support\DB\PluginMigrator;

/**
 * CLI: php vupi migrate [opções]
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
    use RunsKernelMigrations;
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
            echo "\n\033[1;36m=== Vupi.us Migrate ===\033[0m\n\n";
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
                $output = $migrator->status($asJson);
                if ($asJson && $output !== null) {
                    echo $output;
                }
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

            // 3. Plugins em vendor/vupi.us/ — usa conexão de módulos, sem rodar kernel SQL
            if (!$onlyCore) {
                $connLabel = PdoFactory::hasSecondaryConnection() ? 'DB2' : 'DB';
                echo "\n\033[1m[3/3] Migrations de plugins (vendor/vupi.us/) [{$connLabel}]\033[0m\n";
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
}
