<?php

namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\Migrator;
use Src\Kernel\Support\DB\PluginMigrator;

/**
 * CLI: php sweflow migrate [--seed] [--rollback]
 *
 * Ordem de execução:
 *   1. Migrations do kernel  (src/Kernel/Database/migrations/*.sql)
 *   2. Migrations de módulos (src/Modules/*\/Database/Migrations/*.php)
 *   3. Migrations de plugins (vendor/sweflow/*\/src/Modules/*\/Database/Migrations/*.php)
 *   4. Seeders (se --seed)
 *
 * Plugins instalados em src/Modules/ são cobertos pelo passo 2 automaticamente.
 */
class MigrateCommand
{
    public function run(array $args): int
    {
        $seed     = in_array('--seed', $args, true);
        $rollback = in_array('--rollback', $args, true);

        echo "\n\033[1;36m=== Sweflow Migrate ===\033[0m\n\n";

        try {
            $pdo  = PdoFactory::fromEnv();
            $root = dirname(__DIR__, 2);

            $migrator       = new Migrator($pdo, $root);
            $pluginMigrator = new PluginMigrator($pdo, $root);

            if ($rollback) {
                echo "Revertendo última migration...\n";
                $migrator->rollback();
                echo "\n\033[32m✓ Rollback concluído.\033[0m\n\n";
                return 0;
            }

            // 1. Kernel (audit_logs, login_attempts, etc.)
            echo "\033[1m[1/3] Migrations do kernel\033[0m\n";
            $this->runKernelMigrations($pdo);

            // 2. Módulos em src/Modules/ (nativos + plugins instalados aqui)
            echo "\n\033[1m[2/3] Migrations dos módulos (src/Modules/)\033[0m\n";
            $migrator->migrate();

            // 3. Plugins em vendor/sweflow/
            echo "\n\033[1m[3/3] Migrations de plugins (vendor/sweflow/)\033[0m\n";
            $pluginMigrator->migrateAll();

            // 4. Seeders
            if ($seed) {
                echo "\n\033[1m[Seeders] Executando seeders\033[0m\n";
                $migrator->seed();
                $pluginMigrator->seedAll();
            }

            echo "\n\033[32m✓ Concluído.\033[0m\n\n";
            return 0;

        } catch (\Throwable $e) {
            echo "\033[31m✗ Erro: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Executa os arquivos .sql em src/Kernel/Database/migrations/
     * Idempotente: registra na tabela migrations para não re-executar.
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

            // Verifica se já foi executada
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m LIMIT 1");
                $stmt->execute([':m' => 'kernel/' . $name]);
                if ($stmt->fetchColumn()) {
                    echo "  ⊘ kernel/$name (já executada)\n";
                    continue;
                }
            } catch (\Throwable) {
                // Tabela migrations ainda não existe — será criada pelo Migrator
            }

            $sql = file_get_contents($file);
            if ($sql === false) continue;

            $hasError = false;
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if ($statement === '') continue;
                try {
                    $pdo->exec($statement . ';');
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    // Ignora erros inofensivos (já existe, warnings genéricos sem detalhe)
                    $ignorar = str_contains($msg, 'already exists')
                        || $msg === 'SQLSTATE[HY000]: General error: ';
                    if (!$ignorar) {
                        echo "  ⚠ $name: $msg\n";
                        $hasError = true;
                    }
                }
            }

            if (!$hasError) {
                // Registra como executada
                try {
                    $ins = $pdo->prepare("INSERT INTO migrations (module, migration) VALUES ('kernel', :m)");
                    $ins->execute([':m' => 'kernel/' . $name]);
                } catch (\Throwable) {}
                echo "  ✔ kernel/$name\n";
            }
        }
    }
}
