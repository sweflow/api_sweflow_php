<?php
namespace Src\Kernel\Support\DB;

use PDO;
use RuntimeException;

/**
 * Migrator — executa migrations e seeders por módulo, respeitando a conexão
 * definida em cada Database/connection.php ('core' ou 'modules').
 *
 * Regras de ouro:
 *   - Migrations são append-only: NUNCA edite uma migration já executada.
 *   - Para alterar schema, crie uma nova migration (ex: alter_users_add_phone.php).
 *   - Seeders devem ser idempotentes (INSERT IGNORE / ON CONFLICT DO NOTHING).
 */
class Migrator
{
    private PDO    $pdo;
    private PDO    $pdoModules;
    private string $projectRoot;

    /** Cache de connection.php por módulo — evita IO repetido */
    private array $connectionCache = [];

    /** Módulos externos não podem usar conexão core */
    private const EXTERNAL_PATHS = [
        DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR,
    ];

    private const ALLOWED_CONNECTIONS = ['core', 'modules', 'auto'];

    public function __construct(PDO $pdo, string $projectRoot, ?PDO $pdoModules = null)
    {
        $this->pdo         = $pdo;
        $this->pdoModules  = $pdoModules ?? $pdo;
        $this->projectRoot = $projectRoot;

        $this->ensureMigrationsTable($this->pdo);
        if ($this->pdoModules !== $this->pdo) {
            $this->ensureMigrationsTable($this->pdoModules);
        }
    }

    // ── Comandos públicos ─────────────────────────────────────────────────────

    public function migrate(?string $filter = null): void
    {
        $this->withLock($this->pdo, 'sweflow_migrate_core', function () use ($filter) {
            $this->runMigrations($this->discoverModules(), $filter);
        });
    }

    public function migrateCore(): void
    {
        $this->withLock($this->pdo, 'sweflow_migrate_core', function () {
            $modules = array_filter($this->discoverModules(), fn($m) => $this->resolveConnection($m) === 'core');
            $this->runMigrations(array_values($modules));
        });
    }

    public function migrateModules(): void
    {
        $this->withLock($this->pdoModules, 'sweflow_migrate_modules', function () {
            $modules = array_filter($this->discoverModules(), fn($m) => $this->resolveConnection($m) === 'modules');
            $this->runMigrations(array_values($modules));
        });
    }

    public function seed(?string $filter = null): void
    {
        $this->runSeeders($this->discoverModules(), $filter);
    }

    public function seedCore(): void
    {
        $modules = array_filter($this->discoverModules(), fn($m) => $this->resolveConnection($m) === 'core');
        $this->runSeeders(array_values($modules));
    }

    public function seedModules(): void
    {
        $modules = array_filter($this->discoverModules(), fn($m) => $this->resolveConnection($m) === 'modules');
        $this->runSeeders(array_values($modules));
    }

    public function rollback(?string $connection = null): void
    {
        $pdo   = $connection === 'modules' ? $this->pdoModules : $this->pdo;
        $label = $connection ?? 'core';

        $row = $this->lastMigration($pdo);
        if (!$row) {
            echo "  Nenhuma migration aplicada [{$label}]\n";
            return;
        }

        // Extrai nome do módulo e da migration do campo 'migration' (formato: Modulo/nome)
        $parts     = explode('/', $row['migration'], 2);
        $modName   = $parts[0];
        $migName   = $parts[1] ?? $row['migration'];

        $moduleDir = $this->moduleDir($modName);
        $file = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR
              . 'Migrations' . DIRECTORY_SEPARATOR . $migName . '.php';

        if (is_file($file)) {
            $callable = include $file;
            if (is_array($callable) && isset($callable['down']) && is_callable($callable['down'])) {
                $callable['down']($pdo);
                echo "  ✔ Rollback: {$row['migration']} [{$label}]\n";
            } else {
                echo "  ⚠ Rollback sem down() — removendo apenas o registro\n";
            }
        } else {
            echo "  ⚠ Arquivo não encontrado: {$file} — removendo apenas o registro\n";
        }

        // Deleta o registro APÓS executar o down()
        $this->deleteMigration($pdo, (int) $row['id']);
    }

    /**
     * Exibe o status de todas as migrations.
     * @param bool $asJson Se true, imprime JSON puro (sem outros outputs)
     */
    public function status(bool $asJson = false): void
    {
        $modules = $this->discoverModules();
        $result  = ['core' => [], 'modules' => []];

        foreach ($modules as $module) {
            $conn = $this->resolveConnection($module);
            $dir  = $module . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
            if (!is_dir($dir)) continue;

            $pdo   = $this->pdoForConnection($conn);
            $name  = basename($module);
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_NATURAL);

            foreach ($files as $file) {
                $migName = basename($file, '.php');
                $ran     = $this->isExecuted($pdo, $name, $migName);
                $stored  = $this->storedHash($pdo, $name, $migName);
                $current = $this->fileHash($name, $file);
                $changed = $ran && $stored !== null && $stored !== $current;

                $result[$conn][] = [
                    'module'  => $name,
                    'name'    => $migName,
                    'status'  => $ran ? 'done' : 'pending',
                    'changed' => $changed,
                ];
            }
        }

        if ($asJson) {
            // JSON puro — sem nenhum outro output
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        foreach (['core', 'modules'] as $conn) {
            if (empty($result[$conn])) continue;
            echo "\n  \033[1m[{$conn}]\033[0m\n";
            foreach ($result[$conn] as $row) {
                $icon    = $row['status'] === 'done' ? '✔' : '○';
                $color   = $row['status'] === 'done' ? "\033[32m" : "\033[33m";
                $warning = $row['changed'] ? " \033[31m⚠ ALTERADA — crie uma nova migration\033[0m" : '';
                echo "    {$color}{$icon}\033[0m {$row['module']}/{$row['name']}{$warning}\n";
            }
        }
    }

    // ── Execução interna ──────────────────────────────────────────────────────

    private function runMigrations(array $modules, ?string $filter = null): void
    {
        foreach ($modules as $module) {
            $dir = $module . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
            if (!is_dir($dir)) continue;

            $conn = $this->resolveConnection($module);
            $pdo  = $this->pdoForConnection($conn);
            $name = basename($module);

            if ($filter !== null && $conn !== $filter) continue;

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_NATURAL);

            $printed = false;
            foreach ($files as $file) {
                $migName = basename($file, '.php');

                if ($this->isExecuted($pdo, $name, $migName)) {
                    // Detecta alteração — bloqueia e instrui o dev
                    $stored  = $this->storedHash($pdo, $name, $migName);
                    $current = $this->fileHash($name, $file);
                    if ($stored !== null && $stored !== $current) {
                        throw new RuntimeException(
                            "\n  ❌ Migration alterada após execução: {$name}/{$migName}\n" .
                            "     Hash antigo: {$stored}\n" .
                            "     Hash atual:  {$current}\n\n" .
                            "  ⚠  NUNCA edite uma migration já executada.\n" .
                            "     Para alterar o schema, crie uma nova migration:\n" .
                            "     php sweflow make:migration alter_{$name}_table\n"
                        );
                    }
                    continue;
                }

                if (!$printed) {
                    echo "  Migrando módulo \033[36m{$name}\033[0m [{$conn}]\n";
                    $printed = true;
                }

                // Transação por migration — atomicidade garantida
                // MySQL não suporta DDL em transação (auto-commit implícito), mas registra corretamente
                $driver        = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $supportsTx    = $driver === 'pgsql'; // MySQL faz auto-commit em DDL
                $inTransaction = false;
                try {
                    if ($supportsTx && !$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $inTransaction = true;
                    }

                    $callable = include $file;
                    if (is_array($callable) && isset($callable['up']) && is_callable($callable['up'])) {
                        ($callable['up'])($pdo);
                    } elseif (is_callable($callable)) {
                        $callable($pdo);
                    } else {
                        if ($inTransaction) $pdo->rollBack();
                        continue;
                    }

                    $this->markExecuted($pdo, $name, $migName, $this->fileHash($name, $file));

                    if ($inTransaction) $pdo->commit();
                    echo "    ✔ {$migName}\n";

                } catch (\Throwable $e) {
                    if ($inTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw new RuntimeException(
                        "Falha na migration {$name}/{$migName}: " . $e->getMessage(), 0, $e
                    );
                }
            }
        }
    }

    private function runSeeders(array $modules, ?string $filter = null): void
    {
        foreach ($modules as $module) {
            $dir = $module . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
            if (!is_dir($dir)) continue;

            $conn = $this->resolveConnection($module);
            $pdo  = $this->pdoForConnection($conn);
            $name = basename($module);

            if ($filter !== null && $conn !== $filter) continue;

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_NATURAL);

            foreach ($files as $file) {
                $seedName = basename($file, '.php');
                $key      = $name . '/seeders/' . $seedName;
                if ($this->isSeederExecuted($pdo, $key)) continue;

                $callable = include $file;
                if (!is_callable($callable)) continue;

                $callable($pdo);
                $this->markSeederExecuted($pdo, $key, $name);
                echo "    ✔ seeder: {$seedName} [{$conn}]\n";
            }
        }
    }

    // ── Lock de execução ──────────────────────────────────────────────────────

    /**
     * Executa $fn dentro de um lock de banco — garante release no finally.
     * Timeout configurável via MIGRATE_LOCK_TIMEOUT (padrão: 10s).
     */
    private function withLock(PDO $pdo, string $lockName, callable $fn): void
    {
        $driver  = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $timeout = (int) (getenv('MIGRATE_LOCK_TIMEOUT') ?: $_ENV['MIGRATE_LOCK_TIMEOUT'] ?? 10);
        $locked  = false;

        try {
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare("SELECT GET_LOCK(:name, :timeout)");
                $stmt->execute([':name' => $lockName, ':timeout' => $timeout]);
                $result = $stmt->fetchColumn();
                if ($result !== '1' && $result !== 1) {
                    throw new RuntimeException(
                        "Não foi possível obter lock '{$lockName}' em {$timeout}s. " .
                        "Outra migrate pode estar rodando. Ajuste MIGRATE_LOCK_TIMEOUT se necessário."
                    );
                }
                $locked = true;
            } elseif ($driver === 'pgsql') {
                $lockId = abs(crc32($lockName)) % 2147483647;
                $pdo->exec("SELECT pg_advisory_lock({$lockId})");
                $locked = true;
            }
            // SQLite e outros: sem lock (ambiente de dev/teste)

            $fn();

        } finally {
            // SEMPRE libera o lock — mesmo em caso de exceção
            if ($locked) {
                try {
                    if ($driver === 'mysql') {
                        $pdo->prepare("SELECT RELEASE_LOCK(:name)")->execute([':name' => $lockName]);
                    } elseif ($driver === 'pgsql') {
                        $lockId = abs(crc32($lockName)) % 2147483647;
                        $pdo->exec("SELECT pg_advisory_unlock({$lockId})");
                    }
                } catch (\Throwable) {
                    // Ignora erro no release — não deve mascarar exceção original
                }
            }
        }
    }

    // ── Hash ──────────────────────────────────────────────────────────────────

    /**
     * Hash inclui o nome do módulo para evitar colisão entre módulos com
     * migrations de mesmo nome (ex: dois módulos com create_settings_table.php).
     */
    private function fileHash(string $moduleName, string $filePath): string
    {
        return md5($moduleName . '|' . file_get_contents($filePath));
    }

    // ── Resolução de conexão ──────────────────────────────────────────────────

    private function resolveConnection(string $modulePath): string
    {
        if (isset($this->connectionCache[$modulePath])) {
            return $this->connectionCache[$modulePath];
        }

        $isExternal = $this->isExternalModule($modulePath);
        $connection = $this->readConnectionFile($modulePath);

        if (!in_array($connection, self::ALLOWED_CONNECTIONS, true)) {
            throw new RuntimeException(
                "Valor inválido '{$connection}' em Database/connection.php do módulo '" .
                basename($modulePath) . "'. Use: 'core', 'modules' ou 'auto'."
            );
        }

        if ($isExternal && $connection === 'core') {
            throw new RuntimeException(
                "Módulo externo '" . basename($modulePath) . "' não pode usar a conexão 'core'. " .
                "Use 'modules' ou 'auto'."
            );
        }

        $resolved = match ($connection) {
            'auto'  => $isExternal ? 'modules' : 'core',
            default => $connection,
        };

        return $this->connectionCache[$modulePath] = $resolved;
    }

    private function readConnectionFile(string $modulePath): string
    {
        $candidates = [
            $modulePath . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php',
            $modulePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'connection.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $value = include $file;
                if (is_string($value)) return trim($value);
            }
        }
        return $this->isExternalModule($modulePath) ? 'modules' : 'core';
    }

    private function isExternalModule(string $modulePath): bool
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $modulePath);
        foreach (self::EXTERNAL_PATHS as $pattern) {
            if (str_contains($normalized, $pattern)) return true;
        }
        return false;
    }

    private function pdoForConnection(string $connection): PDO
    {
        return $connection === 'modules' ? $this->pdoModules : $this->pdo;
    }

    // ── Descoberta de módulos ─────────────────────────────────────────────────

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
            if ($item === '.' || $item === '..') continue;
            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) $items[] = $fullPath;
        }
        return $items;
    }

    private function moduleDir(string $moduleName): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'src'
             . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $moduleName;
    }

    // ── Tabela migrations ─────────────────────────────────────────────────────

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'pgsql'
            ? "CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                module VARCHAR(255) NOT NULL,
                migration VARCHAR(255) NOT NULL UNIQUE,
                hash VARCHAR(32),
                executed_at TIMESTAMP NOT NULL DEFAULT NOW()
               )"
            : "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(255) NOT NULL,
                migration VARCHAR(255) NOT NULL UNIQUE,
                hash VARCHAR(32),
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
               )";
        $pdo->exec($sql);

        // Adiciona coluna hash em tabelas existentes (upgrade transparente)
        try {
            if ($driver === 'pgsql') {
                $pdo->exec("ALTER TABLE migrations ADD COLUMN IF NOT EXISTS hash VARCHAR(32)");
            } elseif ($driver === 'mysql') {
                $cols = $pdo->query("SHOW COLUMNS FROM migrations LIKE 'hash'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE migrations ADD COLUMN hash VARCHAR(32)");
                }
            }
        } catch (\Throwable) {}
    }

    private function isExecuted(PDO $pdo, string $module, string $migrationName): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
        $stmt->bindValue(':m', $module . '/' . $migrationName);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    private function storedHash(PDO $pdo, string $module, string $migrationName): ?string
    {
        $stmt = $pdo->prepare("SELECT hash FROM migrations WHERE migration = :m");
        $stmt->bindValue(':m', $module . '/' . $migrationName);
        $stmt->execute();
        $row = $stmt->fetchColumn();
        return $row ?: null;
    }

    private function markExecuted(PDO $pdo, string $module, string $migrationName, string $hash): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO migrations (module, migration, hash) VALUES (:module, :migration, :hash)"
        );
        $stmt->bindValue(':module',    $module);
        $stmt->bindValue(':migration', $module . '/' . $migrationName);
        $stmt->bindValue(':hash',      $hash);
        $stmt->execute();
    }

    private function lastMigration(PDO $pdo): ?array
    {
        $stmt = $pdo->query("SELECT * FROM migrations ORDER BY id DESC LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deleteMigration(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("DELETE FROM migrations WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function isSeederExecuted(PDO $pdo, string $key): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
        $stmt->bindValue(':m', $key);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    private function markSeederExecuted(PDO $pdo, string $key, string $module): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO migrations (module, migration) VALUES (:module, :migration)"
        );
        $stmt->bindValue(':module',    $module);
        $stmt->bindValue(':migration', $key);
        $stmt->execute();
    }
}
