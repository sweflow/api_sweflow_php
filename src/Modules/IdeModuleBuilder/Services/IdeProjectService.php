<?php

namespace Src\Modules\IdeModuleBuilder\Services;

use PDO;
use Src\Kernel\Nucleo\ModuleLoader;

/**
 * Gerencia projetos da IDE com isolamento por usuário.
 * Dados em banco de dados (tabela ide_projects) — cada dev só vê seus projetos.
 * Arquivos sincronizados para src/Modules/{user_uuid}_{ModuleName}/ no disco.
 */
class IdeProjectService
{
    private string $modulesBase;
    private PDO $pdo;
    private ModuleLoader $moduleLoader;

    public function __construct(PDO $pdo, ModuleLoader $moduleLoader)
    {
        $root = dirname(__DIR__, 4);
        $this->modulesBase = $root . '/src/Modules';
        $this->pdo = $pdo;
        $this->moduleLoader = $moduleLoader;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ide_projects (
                id UUID PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                module_name VARCHAR(100) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                files JSONB NOT NULL DEFAULT '{}',
                folders JSONB NOT NULL DEFAULT '[]',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ide_projects_user ON ide_projects(user_id)");
            $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ide_projects_user_module ON ide_projects(user_id, module_name)");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ide_projects (
                id CHAR(36) PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                module_name VARCHAR(100) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                files JSON NOT NULL,
                folders JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ide_projects_user (user_id),
                UNIQUE INDEX idx_ide_projects_user_module (user_id, module_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // Tabela de limites individuais por usuário
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ide_user_limits (
                user_id VARCHAR(255) PRIMARY KEY,
                max_projects INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ide_user_limits (
                user_id VARCHAR(255) PRIMARY KEY,
                max_projects INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // ── Projects ─────────────────────────────────────────────────────────

    public function listProjects(string $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, user_id, name, module_name, description, created_at, updated_at, files FROM ide_projects WHERE user_id = :uid ORDER BY updated_at DESC");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $files = json_decode($row['files'] ?? '{}', true) ?: [];
            return [
                'id'          => $row['id'],
                'name'        => $row['name'],
                'module_name' => $row['module_name'],
                'description' => $row['description'] ?? '',
                'created_at'  => $row['created_at'],
                'updated_at'  => $row['updated_at'],
                'file_count'  => count($files),
            ];
        }, $rows);
    }

    public function createProject(string $userId, string $name, string $moduleName, bool $scaffold = true, string $description = ''): array
    {
        // Verifica limite de projetos
        $this->enforceProjectLimit($userId);

        // Verifica disponibilidade completa do nome (reservados + disco + banco)
        $check = $this->checkModuleNameAvailable($moduleName, $userId);
        if (!$check['available']) {
            throw new \RuntimeException($check['reason']);
        }

        $id    = $this->uuid4();
        $now   = date('c');
        $files = $scaffold ? $this->generateScaffold($moduleName) : [];

        $stmt = $this->pdo->prepare("INSERT INTO ide_projects (id, user_id, name, module_name, description, files, folders, created_at, updated_at) VALUES (:id, :uid, :name, :mn, :desc, :files, :folders, :ca, :ua)");
        $stmt->execute([
            ':id'      => $id,
            ':uid'     => $userId,
            ':name'    => $name,
            ':mn'      => $moduleName,
            ':desc'    => $description,
            ':files'   => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':folders' => '[]',
            ':ca'      => $now,
            ':ua'      => $now,
        ]);

        // Cria pasta isolada do módulo em src/Modules/ e sincroniza scaffold
        $this->ensureModuleDir($moduleName);
        foreach ($files as $path => $content) {
            $this->syncFileToModule($moduleName, $path, $content);
        }
        $this->moduleLoader->setEnabled($moduleName, false);

        return [
            'id'          => $id,
            'user_id'     => $userId,
            'name'        => $name,
            'module_name' => $moduleName,
            'description' => $description,
            'created_at'  => $now,
            'updated_at'  => $now,
            'files'       => $files,
        ];
    }

    public function getProject(string $id, string $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ide_projects WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['files']   = json_decode($row['files'] ?? '{}', true) ?: [];
        $row['folders'] = json_decode($row['folders'] ?? '[]', true) ?: [];
        return $row;
    }

    public function deleteProject(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM ide_projects WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    // ── Files ─────────────────────────────────────────────────────────────

    /**
     * Persiste a lista de pastas explícitas (vazias) do projeto.
     */
    public function saveFolders(string $projectId, string $userId, array $folders): bool
    {
        $project = $this->getProject($projectId, $userId);
        if (!$project) return false;

        $moduleName = $project['module_name'];
        $this->ensureModuleDir($moduleName);
        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        $realModule = realpath($moduleDir);
        if ($realModule === false) return false;

        // Pastas que existiam antes (já é array via getProject json_decode)
        $previousFolders = is_array($project['folders']) ? $project['folders'] : [];

        // Normaliza a nova lista
        $newFolders = array_values(array_unique(array_filter($folders)));

        // ── Remove do disco as pastas que saíram da lista ─────────────────
        $removed = array_diff($previousFolders, $newFolders);
        // Ordena do mais profundo para o mais raso (evita rmdir em pasta pai antes da filha)
        usort($removed, fn($a, $b) => substr_count($b, '/') - substr_count($a, '/'));

        foreach ($removed as $folder) {
            $safe = $this->sanitizePath($folder);
            if ($safe === '') continue;
            $target = $realModule . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
            $realTarget = realpath($target);
            if ($realTarget === false || !is_dir($realTarget)) continue;
            // Containment: garante que está dentro do módulo
            if (!str_starts_with($realTarget, $realModule . DIRECTORY_SEPARATOR)) continue;
            try {
                $this->removeDir($realTarget);
            } catch (\Throwable) {
                // Ignora erros de remoção — pasta pode já ter sido removida
            }
        }

        // ── Persiste a nova lista no banco ────────────────────────────────
        $stmt = $this->pdo->prepare("UPDATE ide_projects SET folders = :f, updated_at = :ua WHERE id = :id AND user_id = :uid");
        $stmt->execute([
            ':f'   => json_encode($newFolders, JSON_UNESCAPED_UNICODE),
            ':ua'  => date('c'),
            ':id'  => $projectId,
            ':uid' => $userId,
        ]);

        // ── Cria no disco as pastas novas ─────────────────────────────────
        foreach ($newFolders as $folder) {
            $safe = $this->sanitizePath($folder);
            if ($safe === '') continue;
            $fullPath = $realModule . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
            if (!is_dir($fullPath)) {
                @mkdir($fullPath, 0755, true);
            }
        }

        return true;
    }

    public function saveFile(string $projectId, string $userId, string $path, string $content): bool
    {
        $project = $this->getProject($projectId, $userId);
        if (!$project) return false;

        $path    = $this->sanitizePath($path);
        $content = $this->normalizeNamespace($project['module_name'], $path, $content);

        $files = $project['files'];
        $files[$path] = $content;

        $stmt = $this->pdo->prepare("UPDATE ide_projects SET files = :f, updated_at = :ua WHERE id = :id AND user_id = :uid");
        $stmt->execute([
            ':f'   => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ua'  => date('c'),
            ':id'  => $projectId,
            ':uid' => $userId,
        ]);

        $this->syncFileToModule($project['module_name'], $path, $content);
        return true;
    }

    /**
     * Normaliza o namespace de um arquivo PHP do módulo.
     *
     * Permite que o desenvolvedor escreva apenas o namespace relativo ao módulo:
     *   namespace Task\Controllers;
     *
     * E converte automaticamente para o namespace completo:
     *   namespace Src\Modules\Task\Controllers;
     *
     * Regras:
     *  - Só atua em arquivos .php
     *  - Só atua se o namespace declarado começa com o nome do módulo (sem Src\Modules\)
     *  - Não altera se o namespace já está correto (começa com Src\Modules\)
     *  - Não altera arquivos sem declaração de namespace (routes, connection.php, etc.)
     */
    private function normalizeNamespace(string $moduleName, string $path, string $content): string
    {
        if (!str_ends_with($path, '.php') || trim($content) === '') {
            return $content;
        }

        $fullPrefix = "Src\\Modules\\{$moduleName}";

        // Já está correto — não faz nada
        if (preg_match('/^\s*namespace\s+Src\\\\Modules\\\\/m', $content)) {
            return $content;
        }

        // Detecta namespace relativo que começa com o nome do módulo
        // Ex: "namespace Task\Controllers;" ou "namespace Task;"
        if (preg_match('/^\s*namespace\s+(' . preg_quote($moduleName, '/') . '(?:\\\\[A-Za-z0-9_\\\\]*)?)\s*;/m', $content, $m)) {
            $relative = $m[1]; // ex: "Task\Controllers"
            // Remove o nome do módulo do início para obter o sub-namespace
            $sub = substr($relative, strlen($moduleName));
            $correct = $fullPrefix . $sub; // ex: "Src\Modules\Task\Controllers"
            return preg_replace(
                '/^\s*namespace\s+' . preg_quote($relative, '/') . '\s*;/m',
                "namespace {$correct};",
                $content
            ) ?? $content;
        }

        // Namespace relativo sem o nome do módulo (ex: "namespace Controllers;")
        // Infere o sub-namespace a partir do caminho do arquivo
        if (preg_match('/^\s*namespace\s+([A-Za-z][A-Za-z0-9_\\\\]*)\s*;/m', $content, $m)) {
            $declared = $m[1];
            // Só normaliza se não for um namespace de outro vendor (ex: Src\Kernel)
            if (!str_contains($declared, '\\') || str_starts_with($declared, $moduleName . '\\')) {
                return $content; // já tratado acima ou ambíguo — não altera
            }
        }

        return $content;
    }

    public function deleteFile(string $projectId, string $userId, string $path): bool
    {
        $project = $this->getProject($projectId, $userId);
        if (!$project) return false;

        $path = $this->sanitizePath($path);
        $files = $project['files'];
        unset($files[$path]);

        $stmt = $this->pdo->prepare("UPDATE ide_projects SET files = :f, updated_at = :ua WHERE id = :id AND user_id = :uid");
        $stmt->execute([
            ':f'   => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ua'  => date('c'),
            ':id'  => $projectId,
            ':uid' => $userId,
        ]);

        $this->removeFileFromModule($project['module_name'], $path);
        return true;
    }

    // ── Deploy ────────────────────────────────────────────────────────────

    /**
     * Copia os arquivos do projeto para src/Modules/{ModuleName}/.
     * Retorna status detalhado com arquivos copiados.
     */
    public function deployLocal(array $project): array
    {
        $moduleName = $project['module_name'];

        // Valida nome do módulo — apenas PascalCase, sem path traversal
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) {
            return ['error' => 'Nome do módulo inválido.'];
        }

        // Bloqueia deploy de módulos com nomes reservados pelo kernel
        $reservedNames = \Src\Kernel\Nucleo\ModuleGuard::reservedNames();
        if (in_array($moduleName, $reservedNames, true)) {
            return ['error' => "O nome '{$moduleName}' é reservado pelo sistema e não pode ser usado para módulos externos."];
        }

        $targetDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;

        if (!is_dir($this->modulesBase)) {
            return ['error' => 'Diretório src/Modules não encontrado no servidor.'];
        }

        // Cria o diretório raiz do módulo antes do loop para que realpath() funcione
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                return ['error' => "Não foi possível criar o diretório src/Modules/{$moduleName}."];
            }
        }

        $realTarget = realpath($targetDir);
        if ($realTarget === false) {
            return ['error' => "Não foi possível resolver o caminho de destino src/Modules/{$moduleName}."];
        }

        $copied = [];
        foreach ($project['files'] as $relativePath => $content) {
            // Sanitiza cada caminho — impede path traversal
            $safe = $this->sanitizePath($relativePath);
            if ($safe === '') continue;

            // Ignora arquivos placeholder (.gitkeep)
            if (basename($safe) === '.gitkeep') continue;

            $fullPath = $realTarget . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);

            // Cria subdiretórios necessários
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Garante containment dentro de targetDir (anti path traversal)
            $realDir = realpath($dir);
            if ($realDir === false) continue;

            if (!str_starts_with($realDir . DIRECTORY_SEPARATOR, $realTarget . DIRECTORY_SEPARATOR)
                && $realDir !== $realTarget) {
                continue; // caminho fora do módulo — bloqueado
            }

            file_put_contents($fullPath, $content);
            $copied[] = $safe;
        }

        return [
            'deployed'     => true,
            'target'       => 'local',
            'module_name'  => $moduleName,
            'path'         => 'src/Modules/' . $moduleName,
            'files'        => count($copied),
            'files_list'   => $copied,
        ];
    }

    /**
     * Gera composer.json para publicação no Packagist e retorna instruções.
     */
    public function deployPackagist(array $project, array $options): array
    {
        $moduleName  = $project['module_name'];
        $vendor      = preg_replace('/[^a-z0-9\-]/', '', strtolower($options['vendor'] ?? 'vupi-modules'));
        $packageName = preg_replace('/[^a-z0-9\-]/', '', strtolower($options['package'] ?? $moduleName));
        $description = substr(strip_tags($options['description'] ?? "Módulo {$moduleName} para Vupi.us API"), 0, 255);
        $version     = preg_replace('/[^0-9\.]/', '', $options['version'] ?? '1.0.0') ?: '1.0.0';

        $composerJson = json_encode([
            'name'        => "{$vendor}/{$packageName}",
            'description' => $description,
            'version'     => $version,
            'type'        => 'vupi-module',
            'autoload'    => [
                'psr-4' => ["Src\\Modules\\{$moduleName}\\" => 'src/'],
            ],
            'extra' => [
                'vupi-module' => [
                    'name'      => $moduleName,
                    'namespace' => "Src\\Modules\\{$moduleName}",
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->saveFile($project['id'], $project['user_id'], 'composer.json', $composerJson);

        return [
            'deployed'      => false,
            'target'        => 'packagist',
            'package_name'  => "{$vendor}/{$packageName}",
            'composer_json' => $composerJson,
            'instructions'  => [
                'Crie um repositório no GitHub com os arquivos do projeto',
                'Acesse https://packagist.org/packages/submit e submeta o repositório',
                'Após aprovação, instale via: composer require ' . "{$vendor}/{$packageName}",
                'O módulo ficará disponível no Marketplace do Vupi.us API',
            ],
        ];
    }

    // ── Module Management (apenas o módulo do projeto) ────────────────────

    /**
     * Retorna status do módulo publicado: se existe em src/Modules/, se está ativo.
     * Inclui apenas as tabelas pertencentes ao módulo (via tabela migrations).
     */
    public function getModuleStatus(array $project, PDO $pdo, ?PDO $pdoModules = null): array
    {
        $moduleName = $project['module_name'];
        $moduleDir  = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        $deployed   = is_dir($moduleDir);

        // Estado ativo/inativo via ModuleLoader
        $enabled = $this->moduleLoader->isEnabled($moduleName);

        // Resolve o PDO correto para este módulo (core ou DB2)
        $activePdo = $pdo;
        if ($deployed) {
            $connFile  = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
            $conn      = is_file($connFile) ? (string)(include $connFile) : 'core';
            $activePdo = $this->resolveActivePdo($conn, $pdo, $pdoModules);
        }

        // Tabelas do módulo — lidas da tabela migrations (apenas as do módulo)
        $tables = $deployed ? $this->getModuleTables($moduleName, $activePdo) : [];

        // Migrations e seeders pendentes — verificados no banco correto
        $pendingMigrations = $deployed ? $this->getPendingMigrations($moduleName, $moduleDir, $activePdo) : [];
        $pendingSeeders    = $deployed ? $this->getPendingSeeders($moduleName, $moduleDir, $activePdo) : [];

        return [
            'module_name'        => $moduleName,
            'deployed'           => $deployed,
            'enabled'            => $enabled,
            'path'               => $deployed ? 'src/Modules/' . $moduleName : null,
            'tables'             => $tables,
            'pending_migrations' => $pendingMigrations,
            'pending_seeders'    => $pendingSeeders,
        ];
    }

    /**
     * Roda as migrations do módulo publicado.
     * Usa o Migrator do kernel para garantir consistência.
     */
    public function runMigrations(array $project, PDO $pdo, ?PDO $pdoModules = null): array
    {
        $moduleName = $project['module_name'];
        $moduleDir  = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;

        if (!is_dir($moduleDir)) {
            return ['error' => 'Módulo não publicado. Faça o deploy local primeiro.'];
        }

        $root = dirname($this->modulesBase);
        $migrator = new \Src\Kernel\Support\DB\Migrator($pdo, $root, $pdoModules);

        $ran = [];
        $errors = [];

        $migrDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrDir)) {
            return ['ran' => [], 'message' => 'Nenhuma migration encontrada no módulo.'];
        }

        $files = glob($migrDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);

        // Determina conexão — tenta criar DB2 dinamicamente se necessário
        $connFile  = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        $conn      = is_file($connFile) ? (string)(include $connFile) : 'core';
        $activePdo = $this->resolveActivePdo($conn, $pdo, $pdoModules);

        // Sincroniza connection.php com DEFAULT_MODULE_CONNECTION se estiver desatualizado
        $defaultConn = trim((string) ($_ENV['DEFAULT_MODULE_CONNECTION'] ?? 'core'));
        if (in_array($defaultConn, ['core', 'modules'], true) && $conn !== $defaultConn) {
            $this->syncConnectionFile($moduleDir, $defaultConn);
        }

        $this->ensureMigrationsTable($activePdo);

        foreach ($files as $file) {
            $migName = basename($file, '.php');
            $key = $moduleName . '/' . $migName;

            // Verifica se já foi executada
            $stmt = $activePdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
            $stmt->execute([':m' => $key]);
            if ($stmt->fetchColumn()) {
                continue; // já executada
            }

            // ── Validação server-side: tabelas devem ter prefixo do módulo ──────
            $migContent = (string) file_get_contents($file);
            $validation = $this->validateMigrationTablesDetailed($moduleName, $migContent, $migName, $activePdo);
            if (!$validation['valid']) {
                foreach ($validation['violations'] as $v) {
                    $errors[] = "[{$v['op']} {$v['table']}] {$v['message']}";
                }
                continue; // bloqueia execução desta migration
            }
            try {
                $callable = include $file;
                if (is_array($callable) && isset($callable['up']) && is_callable($callable['up'])) {
                    ($callable['up'])($activePdo);
                } elseif (is_callable($callable)) {
                    $callable($activePdo);
                } else {
                    continue;
                }

                $hash = hash('sha256', $moduleName . '|' . (string)file_get_contents($file));
                $ins = $activePdo->prepare("INSERT INTO migrations (module, migration, hash) VALUES (:mod, :mig, :hash)");
                $ins->execute([':mod' => $moduleName, ':mig' => $key, ':hash' => $hash]);
                $ran[] = $migName;
            } catch (\Throwable $e) {
                $errors[] = $migName . ': ' . $e->getMessage();
            }
        }

        return [
            'ran'    => $ran,
            'errors' => $errors,
            'message' => empty($errors)
                ? (empty($ran) ? 'Todas as migrations já foram executadas.' : count($ran) . ' migration(s) executada(s).')
                : 'Algumas migrations falharam.',
        ];
    }

    /**
     * Valida que uma migration só cria/altera tabelas com o prefixo do módulo.
     * Também verifica se tabelas a criar já existem no banco (pertencentes a outro módulo).
     * Retorna array com 'error' (string|null) e 'violations' (array de detalhes).
     */
    public function validateMigrationTablesDetailed(string $moduleName, string $content, string $migName, PDO $pdo): array
    {
        $systemTables = [
            'usuarios', 'users', 'access_tokens', 'refresh_tokens', 'token_blacklist',
            'audit_logs', 'email_history', 'email_throttle', 'ide_projects', 'ide_user_limits',
            'migrations', 'link_limites', 'link_cliques', 'capabilities', 'sessions',
            'password_resets', 'threat_scores', 'rate_limits',
        ];

        $prefix     = $this->moduleNameToPrefix($moduleName);
        $violations = [];

        // DDL: CREATE, ALTER, DROP, TRUNCATE
        $ddlPattern = '/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';
        preg_match_all($ddlPattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $operation  = strtoupper($match[1]);
            $table      = $match[2];
            $tableLower = strtolower($table);

            // 1. Tabela do sistema — sempre bloqueado
            if (in_array($tableLower, $systemTables, true)) {
                $violations[] = [
                    'type'    => 'SYSTEM_TABLE',
                    'table'   => $table,
                    'op'      => $operation,
                    'message' => "A operação {$operation} na tabela do sistema '{$table}' é proibida. Módulos não podem tocar tabelas do sistema.",
                ];
                continue;
            }

            // 2. Tabela sem prefixo do módulo — bloqueado
            if (!str_starts_with($tableLower, $prefix . '_') && $tableLower !== $prefix) {
                $violations[] = [
                    'type'    => 'WRONG_PREFIX',
                    'table'   => $table,
                    'op'      => $operation,
                    'message' => "A tabela '{$table}' não usa o prefixo obrigatório '{$prefix}_'. Renomeie para '{$prefix}_{$table}'.",
                ];
                continue;
            }

            // 3. CREATE TABLE em tabela que já existe no banco (pertence a outro módulo ou já foi criada)
            if ($operation === 'CREATE') {
                $existsInBank = $this->tableExistsInDatabase($tableLower, $pdo);
                if ($existsInBank) {
                    // Verifica se pertence a este módulo (via tabela migrations)
                    $ownedByThis = $this->tableOwnedByModule($tableLower, $moduleName, $pdo);
                    if (!$ownedByThis) {
                        $violations[] = [
                            'type'    => 'TABLE_EXISTS',
                            'table'   => $table,
                            'op'      => $operation,
                            'message' => "A tabela '{$table}' já existe no banco de dados e pertence a outro módulo. Não é possível criá-la novamente.",
                        ];
                    }
                    // Se pertence a este módulo, a migration já foi executada — ok, será ignorada
                }
            }

            // 4. ALTER/DROP/TRUNCATE em tabela que não pertence a este módulo
            if (in_array($operation, ['ALTER', 'DROP', 'TRUNCATE'], true)) {
                $existsInBank = $this->tableExistsInDatabase($tableLower, $pdo);
                if ($existsInBank) {
                    $ownedByThis = $this->tableOwnedByModule($tableLower, $moduleName, $pdo);
                    if (!$ownedByThis) {
                        $violations[] = [
                            'type'    => 'NOT_OWNED',
                            'table'   => $table,
                            'op'      => $operation,
                            'message' => "A operação {$operation} na tabela '{$table}' é proibida: esta tabela não pertence ao módulo '{$moduleName}'.",
                        ];
                    }
                }
            }
        }

        return [
            'valid'      => empty($violations),
            'violations' => $violations,
            'migration'  => $migName,
        ];
    }

    /**
     * Verifica se uma tabela existe no banco de dados.
     */
    private function tableExistsInDatabase(string $table, PDO $pdo): bool
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?"
                );
            } else {
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
                );
            }
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifica se uma tabela foi criada por este módulo (via tabela migrations).
     */
    private function tableOwnedByModule(string $table, string $moduleName, PDO $pdo): bool
    {
        try {
            // Busca nas migrations do módulo se alguma cria esta tabela
            $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
            $migrDir   = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
            if (!is_dir($migrDir)) return false;

            $files = glob($migrDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            foreach ($files as $file) {
                $content = (string) file_get_contents($file);
                $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[1] as $t) {
                    if (strtolower($t) === $table) return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pré-valida todas as migrations pendentes antes de executar.
     * Retorna array com todas as violações encontradas.
     */
    public function preValidateMigrations(array $project, PDO $pdo, ?PDO $pdoModules = null): array
    {
        $moduleName = $project['module_name'];
        $moduleDir  = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;

        if (!is_dir($moduleDir)) {
            return ['valid' => false, 'error' => 'Módulo não publicado.', 'violations' => []];
        }

        $migrDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrDir)) {
            return ['valid' => true, 'pending' => [], 'violations' => []];
        }

        $connFile  = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        $conn      = is_file($connFile) ? (string)(include $connFile) : 'core';
        $activePdo = $this->resolveActivePdo($conn, $pdo, $pdoModules);

        $this->ensureMigrationsTable($activePdo);

        $files = glob($migrDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);

        $pending    = [];
        $violations = [];

        foreach ($files as $file) {
            $migName = basename($file, '.php');
            $key     = $moduleName . '/' . $migName;

            // Só valida migrations pendentes
            $stmt = $activePdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
            $stmt->execute([':m' => $key]);
            if ($stmt->fetchColumn()) continue;

            $pending[] = $migName;
            $content   = (string) file_get_contents($file);
            $result    = $this->validateMigrationTablesDetailed($moduleName, $content, $migName, $activePdo);

            if (!$result['valid']) {
                $violations = array_merge($violations, $result['violations']);
            }
        }

        return [
            'valid'      => empty($violations),
            'pending'    => $pending,
            'violations' => $violations,
            'module'     => $moduleName,
            'prefix'     => $this->moduleNameToPrefix($moduleName),
        ];
    }

    /**
     * Converte PascalCase para snake_case (prefixo de tabela).
     * Ex: MeuModulo → meu_modulo, LinkEncurtador → link_encurtador
     */
    private function moduleNameToPrefix(string $name): string
    {
        $snake = preg_replace('/([A-Z])/', '_$1', lcfirst($name)) ?? $name;
        return strtolower($snake);
    }

    /**
     * Roda os seeders do módulo publicado.
     */
    public function runSeeders(array $project, PDO $pdo, ?PDO $pdoModules = null): array
    {
        $moduleName = $project['module_name'];
        $moduleDir  = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;

        if (!is_dir($moduleDir)) {
            return ['error' => 'Módulo não publicado. Faça o deploy local primeiro.'];
        }

        $seedDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
        if (!is_dir($seedDir)) {
            return ['ran' => [], 'message' => 'Nenhum seeder encontrado no módulo.'];
        }

        $connFile = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        $conn = is_file($connFile) ? (string)(include $connFile) : 'core';
        $activePdo = $this->resolveActivePdo($conn, $pdo, $pdoModules);

        // Sincroniza connection.php com DEFAULT_MODULE_CONNECTION se estiver desatualizado
        $defaultConn = trim((string) ($_ENV['DEFAULT_MODULE_CONNECTION'] ?? 'core'));
        if (in_array($defaultConn, ['core', 'modules'], true) && $conn !== $defaultConn) {
            $this->syncConnectionFile($moduleDir, $defaultConn);
        }

        $this->ensureMigrationsTable($activePdo);

        $ran = [];
        $errors = [];
        $files = glob($seedDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $seedName = basename($file, '.php');
            $key = $moduleName . '/seeders/' . $seedName;

            $stmt = $activePdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
            $stmt->execute([':m' => $key]);
            if ($stmt->fetchColumn()) continue;

            // ── Validação server-side: seeders só podem acessar tabelas do módulo ──
            $seedContent = (string) file_get_contents($file);
            $validation  = $this->validateMigrationTablesDetailed($moduleName, $seedContent, $seedName, $activePdo);
            if (!$validation['valid']) {
                foreach ($validation['violations'] as $v) {
                    $errors[] = "[Seeder {$seedName}] {$v['message']}";
                }
                continue;
            }

            try {
                $callable = include $file;
                if (!is_callable($callable)) continue;
                $callable($activePdo);

                $ins = $activePdo->prepare("INSERT INTO migrations (module, migration) VALUES (:mod, :mig)");
                $ins->execute([':mod' => $moduleName, ':mig' => $key]);
                $ran[] = $seedName;
            } catch (\Throwable $e) {
                $errors[] = $seedName . ': ' . $e->getMessage();
            }
        }

        return [
            'ran'    => $ran,
            'errors' => $errors,
            'message' => empty($errors)
                ? (empty($ran) ? 'Todos os seeders já foram executados.' : count($ran) . ' seeder(s) executado(s).')
                : 'Alguns seeders falharam.',
        ];
    }

    /**
     * Remove o módulo de src/Modules/ (undeploy).
     * Não remove as tabelas do banco — isso é feito separadamente.
     */
    public function removeModule(array $project): array
    {
        $moduleName = $project['module_name'];
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) {
            return ['error' => 'Nome do módulo inválido.'];
        }

        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        if (!is_dir($moduleDir)) {
            return ['error' => 'Módulo não está publicado.'];
        }

        $this->removeDir($moduleDir);

        // Remove do state via ModuleLoader
        $this->moduleLoader->setEnabled($moduleName, false);

        return ['removed' => true, 'module_name' => $moduleName];
    }

    /**
     * Ativa ou desativa o módulo via ModuleLoader.
     * Atualiza o estado em memória imediatamente E persiste no modules_state.json,
     * garantindo que o guard do ModuleScopedRouter bloqueie as rotas na mesma request.
     */
    public function toggleModule(string $moduleName, bool $enabled): array
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) {
            return ['error' => 'Nome do módulo inválido.'];
        }

        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        if (!is_dir($moduleDir)) {
            return ['error' => 'Módulo não está publicado em src/Modules/.'];
        }

        // Usa ModuleLoader::setEnabled() — atualiza memória + persiste arquivo atomicamente
        $this->moduleLoader->setEnabled($moduleName, $enabled);

        return ['module_name' => $moduleName, 'enabled' => $enabled];
    }

    /**
     * Dropa as tabelas do módulo usando o down() das migrations.
     * Só remove tabelas que pertencem ao módulo (rastreadas na tabela migrations).
     */
    public function dropModuleTables(array $project, PDO $pdo, ?PDO $pdoModules = null): array
    {
        $moduleName = $project['module_name'];
        $moduleDir  = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;

        if (!is_dir($moduleDir)) {
            return ['error' => 'Módulo não está publicado. Não é possível remover tabelas.'];
        }

        $connFile = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        $conn = is_file($connFile) ? (string)(include $connFile) : 'core';
        $activePdo = $this->resolveActivePdo($conn, $pdo, $pdoModules);

        $migrDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrDir)) {
            return ['dropped' => [], 'message' => 'Nenhuma migration encontrada.'];
        }

        // Executa down() em ordem reversa
        $files = glob($migrDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        rsort($files, SORT_NATURAL);

        $dropped = [];
        $errors  = [];

        foreach ($files as $file) {
            $migName = basename($file, '.php');
            $key = $moduleName . '/' . $migName;

            // Só faz rollback se foi executada
            $stmt = $activePdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
            $stmt->execute([':m' => $key]);
            if (!$stmt->fetchColumn()) continue;

            try {
                $callable = include $file;
                if (is_array($callable) && isset($callable['down']) && is_callable($callable['down'])) {
                    ($callable['down'])($activePdo);
                }
                // Remove registro da tabela migrations
                $del = $activePdo->prepare("DELETE FROM migrations WHERE migration = :m");
                $del->execute([':m' => $key]);
                $dropped[] = $migName;
            } catch (\Throwable $e) {
                $errors[] = $migName . ': ' . $e->getMessage();
            }
        }

        // Remove também registros de seeders do módulo
        try {
            $del = $activePdo->prepare("DELETE FROM migrations WHERE module = :mod AND migration LIKE :pattern");
            $del->execute([':mod' => $moduleName, ':pattern' => $moduleName . '/seeders/%']);
        } catch (\Throwable) {}

        return [
            'dropped' => $dropped,
            'errors'  => $errors,
            'message' => empty($errors)
                ? (empty($dropped) ? 'Nenhuma tabela para remover.' : count($dropped) . ' migration(s) revertida(s).')
                : 'Algumas reversões falharam.',
        ];
    }

    // ── Verificação de nome de módulo ─────────────────────────────────────

    /**
     * Verifica se um nome de módulo está disponível.
     * Checa: nomes reservados, pasta em src/Modules/, projetos de TODOS os usuários no banco.
     */
    public function checkModuleNameAvailable(string $name, string $userId): array
    {
        // 1. Nomes reservados pelo kernel
        $reserved = \Src\Kernel\Nucleo\ModuleGuard::reservedNames();
        if (in_array($name, $reserved, true)) {
            return ['available' => false, 'reason' => 'Nome reservado pelo sistema.'];
        }

        // 2. Pasta já existe em src/Modules/ (pode ter sido criada por outro dev ou manualmente)
        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $name;
        if (is_dir($moduleDir)) {
            return ['available' => false, 'reason' => 'Já existe um módulo com este nome no servidor.'];
        }

        // 3. Projeto com esse module_name já existe no banco (qualquer usuário)
        $stmt = $this->pdo->prepare("SELECT user_id FROM ide_projects WHERE LOWER(module_name) = LOWER(:mn) LIMIT 1");
        $stmt->execute([':mn' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $isOwn = ($row['user_id'] === $userId);
            return [
                'available' => false,
                'reason' => $isOwn
                    ? 'Você já tem um projeto com este nome de módulo.'
                    : 'Este nome de módulo já está em uso por outro desenvolvedor.',
            ];
        }

        return ['available' => true, 'reason' => 'Nome disponível.'];
    }

    // ── Limites de projetos ──────────────────────────────────────────────
    //
    // Valores:  -1 = Ilimitado (padrão)
    //            0 = Bloqueado (não pode criar nenhum projeto novo)
    //           >0 = Limite máximo de projetos NOVOS (criados após a definição do limite)
    //
    // Projetos existentes antes da limitação são preservados e não contam.

    /**
     * Verifica se o usuário pode criar mais projetos.
     */
    private function enforceProjectLimit(string $userId): void
    {
        $limit = $this->getEffectiveLimit($userId);

        // -1 = ilimitado
        if ($limit < 0) return;

        // 0 = bloqueado
        if ($limit === 0) {
            throw new \RuntimeException("Sua conta está impedida de criar novos projetos. Entre em contato com o suporte.");
        }

        // N > 0 = conta apenas projetos criados APÓS a definição do limite
        $limitSetAt = $this->getLimitSetAt($userId);
        $count = $this->countProjectsSince($userId, $limitSetAt);

        if ($count >= $limit) {
            throw new \RuntimeException("Limite de projetos atingido ({$count}/{$limit}). Exclua um projeto ou solicite aumento do limite.");
        }
    }

    /**
     * Retorna o limite efetivo para o usuário.
     * Prioridade: individual > global > -1 (ilimitado).
     */
    public function getEffectiveLimit(string $userId): int
    {
        // 1. Limite individual
        try {
            $stmt = $this->pdo->prepare("SELECT max_projects FROM ide_user_limits WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return (int)$row['max_projects'];
            }
        } catch (\Throwable) {}

        // 2. Limite global do .env (-1 = ilimitado por padrão)
        $global = (int)($_ENV['IDE_MAX_PROJECTS_PER_USER'] ?? -1);
        return $global;
    }

    /**
     * Retorna a data em que o limite foi definido para o usuário.
     * Se não tem limite individual, retorna null (usa global, conta todos).
     */
    private function getLimitSetAt(string $userId): ?string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT updated_at FROM ide_user_limits WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row['updated_at'];
            }
        } catch (\Throwable) {}
        return null;
    }

    /**
     * Conta projetos criados após uma data. Se data é null, conta todos.
     */
    private function countProjectsSince(string $userId, ?string $since): int
    {
        if ($since === null) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ide_projects WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ide_projects WHERE user_id = :uid AND created_at >= :since");
            $stmt->execute([':uid' => $userId, ':since' => $since]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Define o limite de projetos para um usuário específico.
     * -1 = ilimitado, 0 = bloqueado, N = limite.
     */
    public function setUserProjectLimit(string $userId, int $limit): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql = "INSERT INTO ide_user_limits (user_id, max_projects, updated_at) VALUES (:uid, :lim, NOW())
                    ON CONFLICT (user_id) DO UPDATE SET max_projects = :lim2, updated_at = NOW()";
            $this->pdo->prepare($sql)->execute([':uid' => $userId, ':lim' => $limit, ':lim2' => $limit]);
        } else {
            $sql = "INSERT INTO ide_user_limits (user_id, max_projects) VALUES (:uid, :lim)
                    ON DUPLICATE KEY UPDATE max_projects = :lim2";
            $this->pdo->prepare($sql)->execute([':uid' => $userId, ':lim' => $limit, ':lim2' => $limit]);
        }
    }

    /**
     * Remove o limite individual (volta a usar o global).
     */
    public function removeUserProjectLimit(string $userId): void
    {
        $this->pdo->prepare("DELETE FROM ide_user_limits WHERE user_id = :uid")->execute([':uid' => $userId]);
    }

    /**
     * Retorna contagem de projetos e limite para um usuário (para exibição).
     */
    public function getUserProjectStats(string $userId): array
    {
        $stmtAll = $this->pdo->prepare("SELECT COUNT(*) FROM ide_projects WHERE user_id = :uid");
        $stmtAll->execute([':uid' => $userId]);
        $totalCount = (int)$stmtAll->fetchColumn();

        $limit = $this->getEffectiveLimit($userId);
        $limitSetAt = $this->getLimitSetAt($userId);
        $countSince = $this->countProjectsSince($userId, $limitSetAt);

        return [
            'count'      => $totalCount,
            'count_since' => $countSince,
            'limit'      => $limit,
            'unlimited'  => $limit < 0,
            'blocked'    => $limit === 0,
            'remaining'  => $limit <= 0 ? null : max(0, $limit - $countSince),
        ];
    }

    // ── Sync com src/Modules/ ─────────────────────────────────────────────

    /**
     * Garante que o diretório src/Modules/{ModuleName}/ exista.
     */
    private function ensureModuleDir(string $moduleName): bool
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) return false;
        if (!is_dir($this->modulesBase)) return false;

        $dir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        if (!is_dir($dir)) {
            return mkdir($dir, 0755, true);
        }
        return true;
    }

    /**
     * Sincroniza um arquivo do projeto IDE para src/Modules/{ModuleName}/{path}.
     * Inclui proteção contra path traversal.
     */
    private function syncFileToModule(string $moduleName, string $relativePath, string $content): bool
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) return false;

        $safe = $this->sanitizePath($relativePath);
        if ($safe === '' || basename($safe) === '.gitkeep') return false;

        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        if (!is_dir($moduleDir)) {
            if (!$this->ensureModuleDir($moduleName)) return false;
        }

        $realModule = realpath($moduleDir);
        if ($realModule === false) return false;

        $fullPath = $realModule . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Anti path traversal
        $realDir = realpath($dir);
        if ($realDir === false) return false;
        if (!str_starts_with($realDir . DIRECTORY_SEPARATOR, $realModule . DIRECTORY_SEPARATOR)
            && $realDir !== $realModule) {
            return false;
        }

        file_put_contents($fullPath, $content);
        return true;
    }

    /**
     * Remove um arquivo de src/Modules/{ModuleName}/{path}.
     */
    private function removeFileFromModule(string $moduleName, string $relativePath): bool
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) return false;

        $safe = $this->sanitizePath($relativePath);
        if ($safe === '') return false;

        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        if (!is_dir($moduleDir)) return false;

        $realModule = realpath($moduleDir);
        if ($realModule === false) return false;

        $fullPath = $realModule . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
        if (!is_file($fullPath)) return true; // já não existe

        // Anti path traversal
        $realFile = realpath($fullPath);
        if ($realFile === false) return false;
        if (!str_starts_with($realFile, $realModule . DIRECTORY_SEPARATOR)) return false;

        unlink($realFile);

        // Remove diretório pai se ficou vazio
        $parentDir = dirname($realFile);
        if ($parentDir !== $realModule && is_dir($parentDir)) {
            $remaining = array_diff(scandir($parentDir) ?: [], ['.', '..']);
            if (empty($remaining)) rmdir($parentDir);
        }

        return true;
    }

    // ── Helpers internos ──────────────────────────────────────────────────

    /**
     * Retorna apenas as tabelas criadas pelo módulo (via tabela migrations).
     * O desenvolvedor NÃO vê tabelas de outros módulos.
     */
    private function getModuleTables(string $moduleName, PDO $pdo): array
    {
        $moduleDir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        $connFile  = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        $conn = is_file($connFile) ? (string)(include $connFile) : 'core';

        // Descobre tabelas a partir dos arquivos de migration do módulo
        $migrDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrDir)) return [];

        $tables = [];
        $files = glob($migrDir . DIRECTORY_SEPARATOR . '*.php') ?: [];

        foreach ($files as $file) {
            $migName = basename($file, '.php');
            $key = $moduleName . '/' . $migName;

            // Só lista tabelas de migrations executadas
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
                $stmt->execute([':m' => $key]);
                if (!$stmt->fetchColumn()) continue;
            } catch (\Throwable) {
                continue;
            }

            // Extrai nomes de tabelas do arquivo de migration via regex simples
            $content = (string)file_get_contents($file);
            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $content, $matches);
            foreach ($matches[1] as $table) {
                if (!in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    private function getPendingMigrations(string $moduleName, string $moduleDir, PDO $pdo): array
    {
        $migrDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrDir)) return [];

        $pending = [];
        $files = glob($migrDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $migName = basename($file, '.php');
            $key = $moduleName . '/' . $migName;
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
                $stmt->execute([':m' => $key]);
                if (!$stmt->fetchColumn()) {
                    $pending[] = $migName;
                }
            } catch (\Throwable) {
                $pending[] = $migName;
            }
        }

        return $pending;
    }

    private function getPendingSeeders(string $moduleName, string $moduleDir, PDO $pdo): array
    {
        $seedDir = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
        if (!is_dir($seedDir)) return [];

        $pending = [];
        $files = glob($seedDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $seedName = basename($file, '.php');
            $key = $moduleName . '/seeders/' . $seedName;
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE migration = :m");
                $stmt->execute([':m' => $key]);
                if (!$stmt->fetchColumn()) {
                    $pending[] = $seedName;
                }
            } catch (\Throwable) {
                $pending[] = $seedName;
            }
        }

        return $pending;
    }

    /**
     * Resolve qual PDO usar para um módulo da IDE.
     *
     * Lógica de prioridade:
     * 1. Se connection.php diz 'modules' → usa DB2
     * 2. Se connection.php diz 'core' MAS DEFAULT_MODULE_CONNECTION=modules → usa DB2
     *    (o connection.php pode estar desatualizado — foi criado antes da configuração mudar)
     * 3. Caso contrário → usa core
     *
     * Se DB2 não estiver disponível, tenta criar a conexão dinamicamente.
     */
    private function resolveActivePdo(string $conn, PDO $pdo, ?PDO $pdoModules): PDO
    {
        // Verifica se DEFAULT_MODULE_CONNECTION aponta para modules
        $defaultConn = trim((string) ($_ENV['DEFAULT_MODULE_CONNECTION'] ?? getenv('DEFAULT_MODULE_CONNECTION') ?: 'core'));
        $useModules  = ($conn === 'modules') || ($defaultConn === 'modules');

        if (!$useModules) {
            return $pdo;
        }

        // Já tem conexão modules disponível no container
        if ($pdoModules !== null) {
            return $pdoModules;
        }

        // Tenta criar conexão DB2 dinamicamente com os valores atuais do .env
        try {
            if (\Src\Kernel\Database\PdoFactory::hasSecondaryConnection()) {
                return \Src\Kernel\Database\PdoFactory::fromEnv('DB2');
            }
        } catch (\Throwable) {
            // Falha silenciosa — cai para core
        }

        return $pdo;
    }

    /**
     * Atualiza o connection.php do módulo para refletir DEFAULT_MODULE_CONNECTION.
     * Chamado automaticamente ao rodar migrations quando o arquivo está desatualizado.
     */
    private function syncConnectionFile(string $moduleDir, string $conn): void
    {
        $connFile = $moduleDir . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        if (!is_dir(dirname($connFile))) {
            return;
        }
        $content = implode("\n", [
            '<?php',
            "// Define qual banco de dados este módulo usa.",
            "// 'core'    → usa DB_* do .env (banco principal)",
            "// 'modules' → usa DB2_* do .env (banco secundário)",
            "// 'auto'    → o Kernel decide baseado na origem do módulo",
            "return '{$conn}';",
            '',
        ]);
        file_put_contents($connFile, $content);
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'pgsql'
            ? "CREATE TABLE IF NOT EXISTS migrations (id SERIAL PRIMARY KEY, module VARCHAR(255) NOT NULL, migration VARCHAR(255) NOT NULL UNIQUE, hash VARCHAR(64), executed_at TIMESTAMP NOT NULL DEFAULT NOW())"
            : "CREATE TABLE IF NOT EXISTS migrations (id INT AUTO_INCREMENT PRIMARY KEY, module VARCHAR(255) NOT NULL, migration VARCHAR(255) NOT NULL UNIQUE, hash VARCHAR(64), executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)";
        try { $pdo->exec($sql); } catch (\Throwable) {}
    }

    // ── Scaffold ──────────────────────────────────────────────────────────

    public function generateScaffold(string $moduleName): array
    {
        // Namespace relativo ao módulo — o prefixo Src\Modules\ é adicionado automaticamente ao salvar
        $ns = $moduleName;

        return [
            // Controllers
            "Controllers/{$moduleName}Controller.php" => $this->tplController($ns, $moduleName),
            // Services
            "Services/{$moduleName}Service.php"       => $this->tplService($ns, $moduleName),
            // Repositories
            "Repositories/{$moduleName}Repository.php" => $this->tplRepository($ns, $moduleName),
            // Entities
            "Entities/{$moduleName}.php"              => $this->tplEntity($ns, $moduleName),
            // DTOs
            "DTOs/Create{$moduleName}DTO.php"         => $this->tplDTO($ns, $moduleName, 'Create'),
            "DTOs/Update{$moduleName}DTO.php"         => $this->tplDTO($ns, $moduleName, 'Update'),
            // Middlewares
            "Middlewares/{$moduleName}Middleware.php"  => $this->tplMiddleware($ns, $moduleName),
            // Validators
            "Validators/{$moduleName}Validator.php"   => $this->tplValidator($ns, $moduleName),
            // Exceptions
            "Exceptions/{$moduleName}Exception.php"   => $this->tplException($ns, $moduleName),
            // Helpers
            "Helpers/{$moduleName}Helper.php"         => $this->tplHelper($ns, $moduleName),
            // Config
            "Config/config.php"                       => $this->tplConfig($moduleName),
            // Database
            "Database/connection.php"                 => $this->tplConnection(),
            "Database/Migrations/2026_01_01_000001_create_{$this->snake($moduleName)}_table.php" => $this->tplMigration($moduleName),
            "Database/Seeders/{$moduleName}Seeder.php" => $this->tplSeeder($moduleName),
            // Routes
            "Routes/web.php"                          => $this->tplRoutes($ns, $moduleName),
            // Docs
            "README.md"                               => $this->tplReadme($moduleName),
        ];
    }

    // ── Templates (string concatenation — safe, no heredoc escaping issues) ──

    private function tplController(string $ns, string $name): string
    {
        $camel = $this->camel($name);
        return implode("\n", [
            '<?php',
            '',
            'namespace ' . $ns . '\\Controllers;',
            '',
            'use Src\\Kernel\\Http\\Request\\Request;',
            'use Src\\Kernel\\Http\\Response\\Response;',
            'use ' . $ns . '\\Services\\' . $name . 'Service;',
            'use ' . $ns . '\\Validators\\' . $name . 'Validator;',
            'use ' . $ns . '\\Exceptions\\' . $name . 'Exception;',
            '',
            'final class ' . $name . 'Controller',
            '{',
            '    public function __construct(',
            '        private readonly ' . $name . 'Service $service',
            '    ) {}',
            '',
            '    public function listar(Request $request): Response',
            '    {',
            '        $page    = max(1, (int) ($request->query[\'page\'] ?? 1));',
            '        $perPage = min(100, max(1, (int) ($request->query[\'per_page\'] ?? 20)));',
            '        return Response::json($this->service->listar($page, $perPage));',
            '    }',
            '',
            '    public function criar(Request $request): Response',
            '    {',
            '        $erro = ' . $name . 'Validator::validarCriacao($request->body);',
            '        if ($erro !== null) {',
            '            return Response::json([\'error\' => $erro], 422);',
            '        }',
            '        try {',
            '            $item = $this->service->criar(' . $name . 'Validator::sanitizar($request->body));',
            '            return Response::json([\'' . $camel . '\' => $item], 201);',
            '        } catch (' . $name . 'Exception $e) {',
            '            return Response::json([\'error\' => $e->getMessage()], $e->getStatusCode());',
            '        }',
            '    }',
            '',
            '    public function buscar(Request $request): Response',
            '    {',
            '        $item = $this->service->buscar($request->params[\'id\'] ?? \'\');',
            '        if ($item === null) {',
            '            return Response::json([\'error\' => \'Nao encontrado.\'], 404);',
            '        }',
            '        return Response::json([\'' . $camel . '\' => $item]);',
            '    }',
            '',
            '    public function atualizar(Request $request): Response',
            '    {',
            '        $erro = ' . $name . 'Validator::validarAtualizacao($request->body);',
            '        if ($erro !== null) {',
            '            return Response::json([\'error\' => $erro], 422);',
            '        }',
            '        try {',
            '            $this->service->atualizar($request->params[\'id\'] ?? \'\', ' . $name . 'Validator::sanitizar($request->body));',
            '            return Response::json([\'updated\' => true]);',
            '        } catch (' . $name . 'Exception $e) {',
            '            return Response::json([\'error\' => $e->getMessage()], $e->getStatusCode());',
            '        }',
            '    }',
            '',
            '    public function deletar(Request $request): Response',
            '    {',
            '        try {',
            '            $this->service->deletar($request->params[\'id\'] ?? \'\');',
            '            return Response::json([\'deleted\' => true]);',
            '        } catch (' . $name . 'Exception $e) {',
            '            return Response::json([\'error\' => $e->getMessage()], $e->getStatusCode());',
            '        }',
            '    }',
            '}',
            '',
        ]);
    }

    private function tplService(string $ns, string $name): string
    {
        return implode("\n", [
            '<?php', '', 'namespace ' . $ns . '\\Services;', '',
            'use ' . $ns . '\\Repositories\\' . $name . 'Repository;',
            'use ' . $ns . '\\Exceptions\\' . $name . 'Exception;', '',
            'final class ' . $name . 'Service', '{',
            '    public function __construct(',
            '        private readonly ' . $name . 'Repository $repository',
            '    ) {}', '',
            '    public function listar(int $page = 1, int $perPage = 20): array',
            '    {',
            '        $total = $this->repository->count();',
            '        $items = $this->repository->findPaginated($page, $perPage);',
            '        return [\'data\' => $items, \'page\' => $page, \'per_page\' => $perPage, \'total\' => $total, \'last_page\' => max(1, (int) ceil($total / $perPage))];',
            '    }', '',
            '    public function criar(array $data): array { return $this->repository->create($data); }', '',
            '    public function buscar(string $id): ?array { return $this->repository->findById($id); }', '',
            '    public function atualizar(string $id, array $data): void',
            '    {',
            '        if ($this->repository->findById($id) === null) { throw ' . $name . 'Exception::naoEncontrado(); }',
            '        $this->repository->update($id, $data);',
            '    }', '',
            '    public function deletar(string $id): void',
            '    {',
            '        if ($this->repository->findById($id) === null) { throw ' . $name . 'Exception::naoEncontrado(); }',
            '        $this->repository->delete($id);',
            '    }',
            '}', '',
        ]);
    }

    private function tplRepository(string $ns, string $name): string
    {
        // Usa o prefixo obrigatório: snake(moduleName)_ + snake(moduleName)s
        $prefix = $this->snake($name) . '_';
        $t      = $prefix . $this->snake($name) . 's'; // ex: task_tasks
        return implode("\n", [
            '<?php', '', 'namespace ' . $ns . '\\Repositories;', '', 'use PDO;', '',
            'final class ' . $name . 'Repository', '{',
            '    private string $table = \'' . $t . '\';', '',
            '    public function __construct(private readonly PDO $pdo) {}', '',
            '    public function count(): int',
            '    {',
            '        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();',
            '    }', '',
            '    public function findPaginated(int $page = 1, int $perPage = 20): array',
            '    {',
            '        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} ORDER BY criado_em DESC LIMIT ? OFFSET ?");',
            '        $stmt->execute([$perPage, ($page - 1) * $perPage]);',
            '        return $stmt->fetchAll(PDO::FETCH_ASSOC);',
            '    }', '',
            '    public function findById(string $id): ?array',
            '    {',
            '        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");',
            '        $stmt->execute([$id]);',
            '        $row = $stmt->fetch(PDO::FETCH_ASSOC);',
            '        return $row !== false ? $row : null;',
            '    }', '',
            '    public function create(array $data): array',
            '    {',
            '        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);',
            '        $id = $driver === \'pgsql\'',
            '            ? $this->pdo->query(\'SELECT gen_random_uuid()\')->fetchColumn()',
            '            : bin2hex(random_bytes(16));',
            '        $this->pdo->prepare("INSERT INTO {$this->table} (id, nome) VALUES (?, ?)")->execute([$id, $data[\'nome\'] ?? \'\']);',
            '        return $this->findById($id) ?? [\'id\' => $id];',
            '    }', '',
            '    public function update(string $id, array $data): void',
            '    {',
            '        $allowed = [\'nome\'];',
            '        $fields = [];',
            '        $values = [];',
            '        foreach ($data as $key => $value) {',
            '            if (in_array($key, $allowed, true)) {',
            '                $fields[] = "{$key} = ?";',
            '                $values[] = $value;',
            '            }',
            '        }',
            '        if ($fields === []) { return; }',
            '        $values[] = $id;',
            '        $this->pdo->prepare("UPDATE {$this->table} SET " . implode(\', \', $fields) . ", atualizado_em = NOW() WHERE id = ?")->execute($values);',
            '    }', '',
            '    public function delete(string $id): void',
            '    {',
            '        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$id]);',
            '    }',
            '}', '',
        ]);
    }

    private function tplEntity(string $ns, string $name): string
    {
        return implode("\n", [
            '<?php', '', 'namespace ' . $ns . '\\Entities;', '',
            'final class ' . $name, '{',
            '    public function __construct(',
            '        private readonly string $id,',
            '        private string $nome,',
            '        private readonly \\DateTimeImmutable $criadoEm,',
            '    ) {}', '',
            '    public function getId(): string { return $this->id; }',
            '    public function getNome(): string { return $this->nome; }',
            '    public function getCriadoEm(): \\DateTimeImmutable { return $this->criadoEm; }', '',
            '    public function toArray(): array',
            '    {',
            '        return [\'id\' => $this->id, \'nome\' => $this->nome, \'criado_em\' => $this->criadoEm->format(\'c\')];',
            '    }',
            '}', '',
        ]);
    }

    private function tplConnection(): string
    {
        // Lê DEFAULT_MODULE_CONNECTION do .env — padrão 'core' se não definido
        $conn = trim((string) ($_ENV['DEFAULT_MODULE_CONNECTION'] ?? getenv('DEFAULT_MODULE_CONNECTION') ?: 'core'));
        if (!in_array($conn, ['core', 'modules', 'auto'], true)) {
            $conn = 'core';
        }
        return implode("\n", [
            '<?php',
            "// Define qual banco de dados este módulo usa.",
            "// 'core'    → usa DB_* do .env (banco principal)",
            "// 'modules' → usa DB2_* do .env (banco secundário)",
            "// 'auto'    → o Kernel decide baseado na origem do módulo",
            "return '{$conn}';",
            '',
        ]);
    }

    private function tplMigration(string $name): string
    {
        // Prefixo obrigatório: snake(moduleName)_ + nome da tabela
        // Ex: módulo "Task" → prefixo "task_" → tabela "task_tasks"
        $prefix = $this->snake($name) . '_';
        $t      = $prefix . $this->snake($name) . 's'; // ex: task_tasks
        return implode("\n", [
            '<?php', '', 'use PDO;', '',
            'return [',
            '    \'up\' => function (PDO $pdo): void {',
            '        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);',
            '        if ($driver === \'pgsql\') {',
            '            $pdo->exec("CREATE TABLE IF NOT EXISTS ' . $t . ' (',
            '                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),',
            '                nome VARCHAR(255) NOT NULL,',
            '                criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),',
            '                atualizado_em TIMESTAMPTZ NULL',
            '            )");',
            '        } else {',
            '            $pdo->exec("CREATE TABLE IF NOT EXISTS ' . $t . ' (',
            '                id CHAR(36) PRIMARY KEY,',
            '                nome VARCHAR(255) NOT NULL,',
            '                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
            '                atualizado_em DATETIME NULL',
            '            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");',
            '        }',
            '    },',
            '    \'down\' => function (PDO $pdo): void {',
            '        $pdo->exec("DROP TABLE IF EXISTS ' . $t . '");',
            '    },',
            '];', '',
        ]);
    }

    private function tplRoutes(string $ns, string $name): string
    {
        $lower = strtolower($name);
        return implode("\n", [
            '<?php', '',
            'use ' . $ns . '\\Controllers\\' . $name . 'Controller;',
            'use Src\\Kernel\\Middlewares\\AuthHybridMiddleware;',
            'use Src\\Kernel\\Middlewares\\AdminOnlyMiddleware;', '',
            '/** @var \\Src\\Kernel\\Contracts\\RouterInterface $router */', '',
            '$auth  = [AuthHybridMiddleware::class];',
            '$admin = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];', '',
            '// Leitura',
            '$router->get(\'/api/' . $lower . '\',          [' . $name . 'Controller::class, \'listar\'],    $auth);',
            '$router->get(\'/api/' . $lower . '/{id}\',     [' . $name . 'Controller::class, \'buscar\'],    $auth);', '',
            '// Escrita',
            '$router->post(\'/api/' . $lower . '\',         [' . $name . 'Controller::class, \'criar\'],     $auth);',
            '$router->put(\'/api/' . $lower . '/{id}\',     [' . $name . 'Controller::class, \'atualizar\'], $auth);', '',
            '// Admin',
            '$router->delete(\'/api/' . $lower . '/{id}\',  [' . $name . 'Controller::class, \'deletar\'],   $admin);', '',
        ]);
    }

    private function tplMiddleware(string $ns, string $name): string
    {
        return '<?php' . "\n\n"
            . 'namespace ' . $ns . '\\Middlewares;' . "\n\n"
            . 'use Src\\Kernel\\Contracts\\MiddlewareInterface;' . "\n"
            . 'use Src\\Kernel\\Http\\Request\\Request;' . "\n"
            . 'use Src\\Kernel\\Http\\Response\\Response;' . "\n\n"
            . '/**' . "\n"
            . ' * Middleware do modulo ' . $name . '.' . "\n"
            . ' *' . "\n"
            . ' * Para usar nas rotas:' . "\n"
            . ' *   $router->get(\'/api/...\', [Controller::class, \'method\'], [' . $name . 'Middleware::class]);' . "\n"
            . ' */' . "\n"
            . 'class ' . $name . 'Middleware implements MiddlewareInterface' . "\n"
            . '{' . "\n"
            . '    public function handle(Request $request, callable $next): Response' . "\n"
            . '    {' . "\n"
            . '        // Logica antes da requisicao' . "\n"
            . '        // Exemplo: validar permissoes, logging, etc.' . "\n"
            . '' . "\n"
            . '        $response = $next($request);' . "\n"
            . '' . "\n"
            . '        // Logica depois da requisicao' . "\n"
            . '' . "\n"
            . '        return $response;' . "\n"
            . '    }' . "\n"
            . '}' . "\n";
    }

    private function tplValidator(string $ns, string $name): string
    {
        return implode("\n", [
            '<?php', '', 'namespace ' . $ns . '\\Validators;', '',
            'final class ' . $name . 'Validator', '{',
            '    public static function validarCriacao(array $data): ?string',
            '    {',
            '        $nome = trim($data[\'nome\'] ?? \'\');',
            '        if ($nome === \'\') { return \'O campo "nome" e obrigatorio.\'; }',
            '        if (mb_strlen($nome) > 255) { return \'O campo "nome" deve ter no maximo 255 caracteres.\'; }',
            '        return null;',
            '    }', '',
            '    public static function validarAtualizacao(array $data): ?string',
            '    {',
            '        if (isset($data[\'nome\']) && mb_strlen($data[\'nome\']) > 255) {',
            '            return \'O campo "nome" deve ter no maximo 255 caracteres.\';',
            '        }',
            '        return null;',
            '    }', '',
            '    public static function sanitizar(array $data): array',
            '    {',
            '        $clean = [];',
            '        if (isset($data[\'nome\'])) { $clean[\'nome\'] = trim(strip_tags($data[\'nome\'])); }',
            '        return $clean;',
            '    }',
            '}', '',
        ]);
    }

    private function tplException(string $ns, string $name): string
    {
        return '<?php' . "\n\n"
            . 'namespace ' . $ns . '\\Exceptions;' . "\n\n"
            . 'class ' . $name . 'Exception extends \\DomainException' . "\n"
            . '{' . "\n"
            . '    private int $statusCode;' . "\n\n"
            . '    public function __construct(string $message, int $statusCode = 400, ?\\Throwable $previous = null)' . "\n"
            . '    {' . "\n"
            . '        parent::__construct($message, 0, $previous);' . "\n"
            . '        $this->statusCode = $statusCode;' . "\n"
            . '    }' . "\n\n"
            . '    public function getStatusCode(): int' . "\n"
            . '    {' . "\n"
            . '        return $this->statusCode;' . "\n"
            . '    }' . "\n\n"
            . '    public static function naoEncontrado(string $recurso = \'Recurso\'): self' . "\n"
            . '    {' . "\n"
            . '        return new self($recurso . \' nao encontrado.\', 404);' . "\n"
            . '    }' . "\n\n"
            . '    public static function validacao(string $mensagem): self' . "\n"
            . '    {' . "\n"
            . '        return new self($mensagem, 422);' . "\n"
            . '    }' . "\n\n"
            . '    public static function naoAutorizado(string $mensagem = \'Acesso nao autorizado.\'): self' . "\n"
            . '    {' . "\n"
            . '        return new self($mensagem, 403);' . "\n"
            . '    }' . "\n"
            . '}' . "\n";
    }

    private function tplConfig(string $name): string
    {
        return implode("\n", [
            '<?php', '', 'return [',
            '    \'name\'        => \'' . $name . '\',',
            '    \'version\'     => \'1.0.0\',',
            '    \'per_page\'    => 20,',
            '    \'max_per_page\' => 100,',
            '    \'rate_limit\'  => 60,',
            '    \'cache_ttl\'   => 0,',
            '    \'sortable\'    => [\'criado_em\', \'nome\'],',
            '    \'searchable\'  => [\'nome\'],',
            '];', '',
        ]);
    }

    private function tplSeeder(string $name): string
    {
        $prefix = $this->snake($name) . '_';
        $t      = $prefix . $this->snake($name) . 's'; // ex: task_tasks
        return implode("\n", [
            '<?php', '', 'use PDO;', '',
            'return function (PDO $pdo): void {',
            '    // Insira dados iniciais aqui',
            '    // $pdo->prepare("INSERT INTO ' . $t . ' (id, nome) VALUES (?, ?)")->execute([\'uuid\', \'Exemplo\']);',
            '};', '',
        ]);
    }

    private function tplDTO(string $ns, string $name, string $prefix): string
    {
        $cls = $prefix . $name . 'DTO';
        return implode("\n", [
            '<?php', '', 'namespace ' . $ns . '\\DTOs;', '',
            'final class ' . $cls, '{',
            '    public function __construct(',
            '        public readonly string $nome,',
            '    ) {}', '',
            '    public static function fromArray(array $data): self',
            '    {',
            '        $nome = trim($data[\'nome\'] ?? \'\');',
            '        if ($nome === \'\') { throw new \\InvalidArgumentException(\'Campo "nome" obrigatorio.\'); }',
            '        return new self(nome: $nome);',
            '    }', '',
            '    public function toArray(): array { return [\'nome\' => $this->nome]; }',
            '}', '',
        ]);
    }

    private function tplHelper(string $ns, string $name): string
    {
        return implode("\n", [
            '<?php', '', 'namespace ' . $ns . '\\Helpers;', '',
            'final class ' . $name . 'Helper', '{',
            '    public static function uuid(): string',
            '    {',
            '        $d = random_bytes(16);',
            '        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);',
            '        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);',
            '        return vsprintf(\'%s%s-%s-%s-%s-%s%s%s\', str_split(bin2hex($d), 4));',
            '    }', '',
            '    public static function formatarData(string $date): string',
            '    {',
            '        return date(\'d/m/Y H:i\', strtotime($date));',
            '    }', '',
            '    public static function slug(string $text): string',
            '    {',
            '        $text = mb_strtolower($text);',
            '        $text = (string) preg_replace(\'/[^a-z0-9\\s-]/\', \'\', $text);',
            '        $text = (string) preg_replace(\'/[\\s-]+/\', \'-\', $text);',
            '        return trim($text, \'-\');',
            '    }',
            '}', '',
        ]);
    }

    private function tplReadme(string $name): string
    {
        $lower = strtolower($name);
        return implode("\n", [
            '# Modulo ' . $name, '',
            'Modulo para a Vupi.us API.', '',
            '## Rotas', '',
            '| Metodo | URI | Descricao |',
            '|--------|-----|-----------|',
            '| GET | `/api/' . $lower . '` | Listar (paginado) |',
            '| GET | `/api/' . $lower . '/{id}` | Buscar por ID |',
            '| POST | `/api/' . $lower . '` | Criar |',
            '| PUT | `/api/' . $lower . '/{id}` | Atualizar |',
            '| DELETE | `/api/' . $lower . '/{id}` | Deletar (admin) |', '',
            '## Conectar aplicacao externa', '',
            '1. Solicite ao suporte da Vupi.us API a liberacao no CORS da URL do frontend.',
            '2. O admin adicionara a URL via Dashboard > Configuracoes > CORS.',
            '3. Apos aprovacao, sua aplicacao podera fazer requisicoes a API.', '',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function snake(string $name): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)) ?? $name);
    }

    private function camel(string $name): string
    {
        return lcfirst($name);
    }

    private function sanitizePath(string $path): string
    {
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        return ltrim($path, '/');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
