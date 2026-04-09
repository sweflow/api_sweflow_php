<?php

namespace Src\CLI;

/**
 * Shared logic for running kernel SQL migrations.
 * Used by both SetupCommand and MigrateCommand.
 */
trait RunsKernelMigrations
{
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

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            // Executa o arquivo SQL inteiro de uma vez — mais confiável que split por ';'
            // que pode quebrar strings contendo ponto-e-vírgula
            $hasError = false;
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                $msg     = $e->getMessage();
                $ignorar = str_contains($msg, 'already exists')
                    || $msg === 'SQLSTATE[HY000]: General error: ';
                if (!$ignorar) {
                    echo "  ⚠ $name: $msg\n";
                    $hasError = true;
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
