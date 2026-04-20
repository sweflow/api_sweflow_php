<?php

namespace Src\Modules\IdeModuleBuilder\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\IdeModuleBuilder\Repositories\DatabaseConnectionRepository;
use Src\Modules\IdeModuleBuilder\Services\DatabaseConnectionService;
use Src\Modules\IdeModuleBuilder\Services\IdeProjectService;
use PDO;

class DatabaseStatusController
{
    private DatabaseConnectionRepository $repository;
    private DatabaseConnectionService $service;
    private IdeProjectService $projectService;
    private PDO $pdo;
    private ?PDO $pdoModules;

    public function __construct(
        DatabaseConnectionRepository $repository,
        DatabaseConnectionService $service,
        IdeProjectService $projectService,
        PDO $pdo,
        ?PDO $pdoModules = null
    ) {
        $this->repository = $repository;
        $this->service = $service;
        $this->projectService = $projectService;
        $this->pdo = $pdo;
        $this->pdoModules = $pdoModules;
    }

    /**
     * Verifica o status da conexão ativa (migrations pendentes e tabelas)
     * GET /api/ide/database-status
     * 
     * Retorna o status agregado de TODOS os projetos do desenvolvedor.
     */
    public function status(Request $request): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        try {
            // Busca conexão ativa
            $activeConnection = $this->repository->findActiveByUser($usuarioUuid);
            
            if (!$activeConnection) {
                return Response::json([
                    'has_connection' => false,
                    'message' => 'Nenhuma conexão ativa',
                ]);
            }
            
            // Cria PDO com a conexão personalizada do desenvolvedor (com persistent connection)
            $customPdo = $this->service->createPdoConnection([
                'service_uri' => $activeConnection['service_uri'],
                'database_name' => $activeConnection['database_name'],
                'host' => $activeConnection['host'],
                'port' => $activeConnection['port'],
                'username' => $activeConnection['username'],
                'password' => $this->repository->decryptPassword($activeConnection['password']),
                'driver' => $activeConnection['driver'],
                'ssl_mode' => $activeConnection['ssl_mode'],
                'ca_certificate' => $activeConnection['ca_certificate'],
                'persistent' => true, // ← PERSISTENT CONNECTION
            ]);
            
            $driver = $activeConnection['driver'];
            
            // Executa operações em paralelo (não bloqueia)
            // 1. Lista tabelas (rápido - apenas query)
            $tables = $this->listTables($customPdo, $driver, $activeConnection['database_name']);
            
            // 2. Conta projetos (rápido - apenas query)
            $projectsCount = $this->projectService->countProjects($usuarioUuid);
            
            // 3. Verifica migrations pendentes (OTIMIZADO - sem I/O pesado)
            $pendingMigrations = $this->checkPendingMigrationsOptimized($usuarioUuid, $customPdo);
            
            return Response::json([
                'has_connection' => true,
                'connection' => [
                    'name' => $activeConnection['connection_name'],
                    'driver' => $driver,
                    'host' => $activeConnection['host'],
                    'port' => $activeConnection['port'],
                    'database' => $activeConnection['database_name'],
                ],
                'tables' => $tables,
                'pending_migrations' => $pendingMigrations,
                'has_pending_migrations' => count($pendingMigrations) > 0,
                'projects_count' => $projectsCount,
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'Erro ao verificar status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lista todas as tabelas do banco de dados (versão otimizada - sem tamanho)
     */
    private function listTables(PDO $pdo, string $driver, string $databaseName): array
    {
        try {
            if ($driver === 'pgsql') {
                // Query otimizada - sem cálculo de tamanho (muito lento)
                $stmt = $pdo->query("
                    SELECT table_name
                    FROM information_schema.tables
                    WHERE table_schema = 'public'
                    AND table_type = 'BASE TABLE'
                    ORDER BY table_name
                ");
            } else {
                // MySQL - query otimizada
                $stmt = $pdo->query("
                    SELECT table_name
                    FROM information_schema.tables
                    WHERE table_schema = '{$databaseName}'
                    AND table_type = 'BASE TABLE'
                    ORDER BY table_name
                ");
            }
            
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = [
                    'name' => $row['table_name'],
                    // Tamanho removido para performance - pode ser adicionado em endpoint separado se necessário
                ];
            }
            
            return $tables;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Verifica migrations pendentes APENAS dos projetos do desenvolvedor.
     * Usa o IdeProjectService que agora prioriza a conexão personalizada automaticamente.
     */
    private function checkPendingMigrationsForUser(string $userId): array
    {
        try {
            // Busca todos os projetos do desenvolvedor
            $projects = $this->projectService->listProjects($userId);
            
            if (empty($projects)) {
                return [];
            }
            
            $allPending = [];
            
            foreach ($projects as $projectSummary) {
                // Busca o projeto completo para ter acesso aos dados necessários
                $project = $this->projectService->getProject($projectSummary['id'], $userId);
                
                if (!$project) {
                    continue;
                }
                
                // getModuleStatus agora usa automaticamente a conexão personalizada do desenvolvedor
                $status = $this->projectService->getModuleStatus($project, $this->pdo, $this->pdoModules);
                
                // Adiciona as migrations pendentes deste projeto ao total
                if (!empty($status['pending_migrations'])) {
                    foreach ($status['pending_migrations'] as $migration) {
                        $allPending[] = $project['module_name'] . '/' . $migration;
                    }
                }
            }
            
            return $allPending;
        } catch (\Exception $e) {
            // Em caso de erro, retorna array vazio em vez de assumir que todas estão pendentes
            return [];
        }
    }

    /**
     * Versão OTIMIZADA - Verifica migrations pendentes sem I/O pesado.
     * Apenas compara tabela migrations com módulos deployados.
     */
    private function checkPendingMigrationsOptimized(string $userId, PDO $customPdo): array
    {
        try {
            // Busca apenas nomes dos módulos (query leve)
            $stmt = $this->pdo->prepare("
                SELECT module_name 
                FROM ide_projects 
                WHERE user_id = ? 
                ORDER BY module_name
            ");
            $stmt->execute([$userId]);
            $moduleNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($moduleNames)) {
                return [];
            }
            
            // Verifica quais módulos estão deployados (existem no disco)
            $modulesBase = dirname(__DIR__, 3) . '/src/Modules';
            $deployedModules = [];
            
            foreach ($moduleNames as $moduleName) {
                $moduleDir = $modulesBase . DIRECTORY_SEPARATOR . $moduleName;
                if (is_dir($moduleDir)) {
                    $deployedModules[] = $moduleName;
                }
            }
            
            if (empty($deployedModules)) {
                return [];
            }
            
            // Busca migrations já executadas no banco personalizado
            try {
                $stmt = $customPdo->query("SELECT migration FROM migrations ORDER BY migration");
                $ranMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Exception $e) {
                // Tabela migrations não existe ainda
                $ranMigrations = [];
            }
            
            $allPending = [];
            
            // Para cada módulo deployado, verifica migrations pendentes
            foreach ($deployedModules as $moduleName) {
                $moduleDir = $modulesBase . DIRECTORY_SEPARATOR . $moduleName;
                $migrDir = $moduleDir . '/Database/Migrations';
                
                if (!is_dir($migrDir)) {
                    continue;
                }
                
                // Lista arquivos de migration (I/O local - rápido)
                $files = glob($migrDir . '/*.php') ?: [];
                
                foreach ($files as $file) {
                    $migName = basename($file, '.php');
                    $key = $moduleName . '/' . $migName;
                    
                    // Verifica se já foi executada
                    if (!in_array($key, $ranMigrations, true)) {
                        $allPending[] = $key;
                    }
                }
            }
            
            return $allPending;
        } catch (\Exception $e) {
            return [];
        }
    }
}
