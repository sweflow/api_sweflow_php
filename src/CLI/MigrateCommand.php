<?php

namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\Migrator;
use Src\Kernel\Support\DB\PluginMigrator;

/**
 * CLI: php sweflow migrate [--seed] [--rollback] [--module=NomeModulo]
 *
 * Executa migrations de todos os módulos em src/Modules/
 * Cada módulo pode ter:
 *   src/Modules/NomeModulo/Database/Migrations/*.php
 *   src/Modules/NomeModulo/Database/Seeders/*.php
 */
class MigrateCommand
{
    public function run(array $args): int
    {
        $seed     = in_array('--seed', $args, true);
        $rollback = in_array('--rollback', $args, true);
        $module   = $this->getOption($args, '--module');

        echo "\n\033[1;36m=== Sweflow Migrate ===\033[0m\n\n";

        try {
            $pdo      = PdoFactory::fromEnv();
            $root     = dirname(__DIR__, 2);
            $migrator = new Migrator($pdo, $root);

            if ($rollback) {
                echo "Revertendo última migration...\n";
                $migrator->rollback();
            } else {
                echo "Executando migrations dos módulos...\n";
                $migrator->migrate();

                if ($seed) {
                    echo "\nExecutando seeders...\n";
                    $migrator->seed();
                }
            }

            // Migrations de segurança do kernel
            $this->runKernelMigrations($pdo);

            echo "\n\033[32m✓ Concluído.\033[0m\n\n";
            return 0;
        } catch (\Throwable $e) {
            echo "\033[31m✗ Erro: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    private function runKernelMigrations(\PDO $pdo): void
    {
        $dir = dirname(__DIR__) . '/Kernel/Database/migrations';
        if (!is_dir($dir)) return;

        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $name = basename($file);
            // Verifica se já foi executada
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m LIMIT 1");
                $stmt->execute([':m' => 'kernel/' . $name]);
                if ($stmt->fetchColumn()) continue;
            } catch (\Throwable) {
                // Tabela migrations pode não existir ainda — ignora
            }

            $sql = file_get_contents($file);
            if ($sql === false) continue;

            // Executa cada statement separadamente
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    try { $pdo->exec($stmt . ';'); } catch (\Throwable $e) {
                        // Ignora erros de "já existe" (IF NOT EXISTS)
                        if (!str_contains($e->getMessage(), 'already exists')) {
                            echo "  ⚠ " . $e->getMessage() . "\n";
                        }
                    }
                }
            }

            // Marca como executada
            try {
                $ins = $pdo->prepare("INSERT INTO migrations (module, migration) VALUES ('kernel', :m)");
                $ins->execute([':m' => 'kernel/' . $name]);
            } catch (\Throwable) {}

            echo "  ✔ kernel/$name\n";
        }
    }

    private function getOption(array $args, string $name): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
        }
        return null;
    }
}
