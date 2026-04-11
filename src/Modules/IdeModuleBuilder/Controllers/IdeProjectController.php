<?php

declare(strict_types=1);

namespace Src\Modules\IdeModuleBuilder\Controllers;

use PDO;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\IdeModuleBuilder\Services\IdeProjectService;
use Src\Modules\IdeModuleBuilder\Services\ModuleAnalyzer;
use Src\Modules\IdeModuleBuilder\Services\ModuleAutoFixer;
use Src\Modules\IdeModuleBuilder\Services\PhpExecutor;

final class IdeProjectController
{
    private const EXECUTOR_TIMEOUT = 30;

    public function __construct(
        private readonly IdeProjectService $service,
        private readonly PDO $pdo,
        private readonly ?PDO $pdoModules = null,
    ) {}

    // ── Helpers ───────────────────────────────────────────────────────────

    private function requireUser(Request $request): array
    {
        $user = $request->attribute('auth_user');
        if ($user === null) {
            return [null, Response::json(['error' => 'Nao autenticado.'], 401)];
        }
        return [$user->getUuid()->toString(), null];
    }

    private function requireProject(Request $request): array
    {
        [$userId, $err] = $this->requireUser($request);
        if ($err !== null) {
            return [null, null, $err];
        }
        $id = $request->params['id'] ?? '';
        $project = $this->service->getProject($id, $userId);
        if ($project === null) {
            return [null, null, Response::json(['error' => 'Projeto nao encontrado.'], 404)];
        }
        return [$userId, $project, null];
    }

    private function errorResponse(string $msg, int $code = 400): Response
    {
        return Response::json(['error' => $msg], $code);
    }

    private function executorErrorResponse(): Response
    {
        return Response::json([
            'output' => '', 'errors' => 'Erro interno ao executar.',
            'exit_code' => 1, 'duration_ms' => 0, 'type' => 'error',
        ]);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function list(Request $request): Response
    {
        [$userId, $err] = $this->requireUser($request);
        if ($err) return $err;
        return Response::json(['projects' => $this->service->listProjects($userId)]);
    }

    public function create(Request $request): Response
    {
        [$userId, $err] = $this->requireUser($request);
        if ($err) return $err;

        $body        = $request->body;
        $name        = trim((string) ($body['name'] ?? ''));
        $moduleName  = trim((string) ($body['module_name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        if ($name === '' || $moduleName === '') {
            return $this->errorResponse('name e module_name sao obrigatorios.', 422);
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) {
            return $this->errorResponse('module_name deve ser PascalCase, apenas letras e numeros.', 422);
        }

        $scaffold = filter_var($body['scaffold'] ?? true, FILTER_VALIDATE_BOOLEAN);

        try {
            $project = $this->service->createProject($userId, $name, $moduleName, $scaffold, $description);
            return Response::json(['project' => $project], 201);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $isBlocked = str_contains($msg, 'impedida') || str_contains($msg, 'Limite');
            return $this->errorResponse($msg, $isBlocked ? 403 : 422);
        }
    }

    public function get(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        return Response::json(['project' => $project]);
    }

    public function delete(Request $request): Response
    {
        [$userId, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $result = ['deleted' => true, 'module_name' => $project['module_name']];

        try {
            $drop = $this->service->dropModuleTables($project, $this->pdo, $this->pdoModules);
            $result['tables_dropped'] = $drop['dropped'] ?? [];
        } catch (\Throwable) {
            $result['tables_dropped'] = [];
        }

        try { $this->service->removeModule($project); $result['module_removed'] = true; }
        catch (\Throwable) { $result['module_removed'] = false; }

        $this->service->deleteProject($project['id'], $userId);
        return Response::json($result);
    }

    // ── Files ─────────────────────────────────────────────────────────────

    public function saveFolders(Request $request): Response
    {
        [$userId, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $folders = $request->body['folders'] ?? [];
        if (!is_array($folders)) {
            return $this->errorResponse('folders deve ser um array.', 422);
        }

        $clean = array_values(array_filter(array_map(function ($f) {
            $f = str_replace(['..', '\\'], ['', '/'], trim((string) $f));
            return trim($f, '/');
        }, $folders)));

        return Response::json(['saved' => $this->service->saveFolders($project['id'], $userId, $clean)]);
    }

    public function saveFile(Request $request): Response
    {
        [$userId, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $path    = trim((string) ($request->body['path'] ?? ''));
        $content = (string) ($request->body['content'] ?? '');

        if ($path === '') {
            return $this->errorResponse('path e obrigatorio.', 422);
        }

        // ── Validação server-side: verifica conteúdo PHP ao salvar ──────────
        if (str_ends_with($path, '.php') && $content !== '') {
            $violation = $this->validateCodeTableAccess($content, $project['module_name']);
            if ($violation !== null) {
                return Response::json([
                    'saved' => false,
                    'error' => $violation,
                    'security_block' => true,
                ], 403);
            }
        }

        $ok = $this->service->saveFile($project['id'], $userId, $path, $content);
        return $ok ? Response::json(['saved' => true]) : $this->errorResponse('Falha ao salvar.', 500);
    }

    public function deleteFile(Request $request): Response
    {
        [$userId, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $path = trim((string) ($request->body['path'] ?? ''));
        if ($path === '') {
            return $this->errorResponse('path e obrigatorio.', 422);
        }
        $this->service->deleteFile($project['id'], $userId, $path);
        return Response::json(['deleted' => true]);
    }

    // ── Analysis & Deploy ─────────────────────────────────────────────────

    public function analyze(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $analyzer = new ModuleAnalyzer($project['module_name'], $project['files'] ?? []);
        return Response::json(['analysis' => $analyzer->analyze()]);
    }

    public function autofix(Request $request): Response
    {
        [$userId, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $files = $project['files'] ?? [];
        $analyzer = new ModuleAnalyzer($project['module_name'], $files);
        $issues = $analyzer->analyze()['issues'] ?? [];

        $fixer = new ModuleAutoFixer($project['module_name'], $files);
        $result = $fixer->fix($issues);

        $savedCount = 0;
        foreach ($result['files'] as $path => $newContent) {
            if (($files[$path] ?? null) !== $newContent) {
                if ($this->service->saveFile($project['id'], $userId, $path, $newContent)) {
                    $savedCount++;
                }
            }
        }

        $newReport = (new ModuleAnalyzer($project['module_name'], $result['files']))->analyze();

        return Response::json([
            'applied'     => $result['applied'],
            'skipped'     => $result['skipped'],
            'files_saved' => $savedCount,
            'files'       => $result['files'],
            'analysis'    => $newReport,
        ]);
    }

    public function deploy(Request $request): Response
    {
        [$userId, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $target = trim((string) ($request->body['target'] ?? 'local'));

        if ($target === 'local') {
            return $this->deployLocal($project);
        }
        if ($target === 'packagist') {
            return Response::json($this->service->deployPackagist($project, $request->body));
        }
        return $this->errorResponse('target invalido. Use "local" ou "packagist".', 422);
    }

    private function deployLocal(array $project): Response
    {
        $files = $project['files'] ?? [];
        if (empty($files)) {
            return $this->errorResponse('O projeto nao tem arquivos para publicar.', 422);
        }

        $analyzer = new ModuleAnalyzer($project['module_name'], $files);
        $report = $analyzer->analyze();

        if (!$report['can_deploy']) {
            return Response::json(['error' => 'Publicacao bloqueada.', 'analysis' => $report], 422);
        }

        $result = $this->service->deployLocal($project);
        $result['analysis'] = $report;

        $result['migrations'] = $this->service->runMigrations($project, $this->pdo, $this->pdoModules);
        if (empty($result['migrations']['errors'] ?? [])) {
            $result['seeders'] = $this->service->runSeeders($project, $this->pdo, $this->pdoModules);
        }

        return Response::json($result);
    }

    // ── Module Management ─────────────────────────────────────────────────

    public function status(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        return Response::json(['status' => $this->service->getModuleStatus($project, $this->pdo)]);
    }

    public function migrate(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        $result = $this->service->runMigrations($project, $this->pdo, $this->pdoModules);
        return Response::json($result, isset($result['error']) ? 400 : 200);
    }

    public function preValidateMigrations(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        $result = $this->service->preValidateMigrations($project, $this->pdo, $this->pdoModules);
        return Response::json($result);
    }
    public function seed(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        $result = $this->service->runSeeders($project, $this->pdo, $this->pdoModules);
        return Response::json($result, isset($result['error']) ? 400 : 200);
    }

    public function removeModule(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $drop = $this->service->dropModuleTables($project, $this->pdo, $this->pdoModules);
        $result = $this->service->removeModule($project);
        $result['tables_dropped'] = $drop['dropped'] ?? [];
        return Response::json($result, isset($result['error']) ? 400 : 200);
    }

    public function toggleModule(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        $enabled = filter_var($request->body['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $result = $this->service->toggleModule($project['module_name'], $enabled);
        return Response::json($result, isset($result['error']) ? 400 : 200);
    }

    public function dropTables(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;
        $result = $this->service->dropModuleTables($project, $this->pdo, $this->pdoModules);
        return Response::json($result, isset($result['error']) ? 400 : 200);
    }

    // ── Scaffold & Constraints ────────────────────────────────────────────

    public function lint(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $path    = trim((string) ($request->body['path'] ?? ''));
        $content = (string) ($request->body['content'] ?? '');

        if ($path === '' || $content === '' || !str_ends_with($path, '.php')) {
            return Response::json(['diagnostics' => []]);
        }

        // Passa todos os arquivos do projeto para que o analyzer tenha contexto completo:
        // - NO_ROUTES_FILE não dispara em arquivos que não são o módulo inteiro
        // - MISSING_CONTROLLER encontra os controllers nos outros arquivos do projeto
        $allFiles = $project['files'] ?? [];
        // Substitui o conteúdo do arquivo atual pelo conteúdo enviado (pode ter edições não salvas)
        $allFiles[$path] = $content;

        $analyzer = new \Src\Modules\IdeModuleBuilder\Services\ModuleAnalyzer(
            $project['module_name'],
            $allFiles
        );
        $report = $analyzer->analyze();

        $fixableCodes = [
            'MISSING_UP', 'MISSING_DOWN', 'NO_DRIVER_CHECK', 'NON_IDEMPOTENT_MIGRATION',
            'UNPROTECTED_WRITE_ROUTE', 'SHELL_EXECUTION', 'EVAL_USAGE',
            'DIRECT_HEADER', 'DIE_EXIT', 'DIE_EXIT_IN_CONTROLLER',
            'MISSING_NAMESPACE', 'WRONG_NAMESPACE', 'INVALID_CONNECTION',
        ];

        // Filtra apenas os diagnósticos do arquivo atual (ou sem arquivo — estrutura global)
        // Exclui NO_ROUTES_FILE e MISSING_CONTROLLER do lint single-file: esses são
        // problemas de estrutura do módulo inteiro, não do arquivo sendo editado.
        $structureCodes = ['NO_ROUTES_FILE', 'EMPTY_PROJECT'];

        $diagnostics = [];
        foreach ($report['issues'] ?? [] as $issue) {
            $issueFile = $issue['file'] ?? '';
            // Inclui apenas issues do arquivo atual ou issues globais que não sejam de estrutura
            if ($issueFile !== $path && ($issueFile !== '' || in_array($issue['code'], $structureCodes, true))) {
                continue;
            }
            // Suprime NO_ROUTES_FILE no lint — só faz sentido no analyze() completo
            if (in_array($issue['code'], $structureCodes, true)) {
                continue;
            }
            $diagnostics[] = [
                'line'       => max(1, (int) ($issue['line'] ?? 1)),
                'column'     => 1,
                'severity'   => $issue['severity'] === 'error' ? 8 : ($issue['severity'] === 'warning' ? 4 : 1),
                'message'    => $issue['message'],
                'code'       => $issue['code'],
                'suggestion' => $issue['suggestion'] ?? '',
                'fixable'    => in_array($issue['code'], $fixableCodes, true),
            ];
        }

        return Response::json(['diagnostics' => $diagnostics]);
    }

    public function scaffold(Request $request): Response
    {
        $moduleName = trim((string) ($request->body['module_name'] ?? ''));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) {
            return $this->errorResponse('module_name invalido.', 422);
        }
        return Response::json(['files' => $this->service->generateScaffold($moduleName)]);
    }

    public function constraints(Request $request): Response
    {
        return Response::json([
            'reserved_names'    => \Src\Kernel\Nucleo\ModuleGuard::reservedNames(),
            'reserved_prefixes' => \Src\Kernel\Nucleo\ModuleGuard::reservedPrefixes(),
        ]);
    }

    public function checkModuleName(Request $request): Response
    {
        [$userId, $err] = $this->requireUser($request);
        if ($err) return $err;

        $name = trim($request->params['name'] ?? '');
        if ($name === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
            return Response::json(['available' => false, 'reason' => 'Nome invalido.']);
        }
        return Response::json($this->service->checkModuleNameAvailable($name, $userId));
    }

    // ── Limits ────────────────────────────────────────────────────────────

    public function myLimits(Request $request): Response
    {
        [$userId, $err] = $this->requireUser($request);
        if ($err) return $err;
        return Response::json($this->service->getUserProjectStats($userId));
    }

    public function getUserLimit(Request $request): Response
    {
        $uid = $request->params['userId'] ?? '';
        if ($uid === '') return $this->errorResponse('userId obrigatorio.', 422);
        return Response::json($this->service->getUserProjectStats($uid));
    }

    public function setUserLimit(Request $request): Response
    {
        $uid = $request->params['userId'] ?? '';
        if ($uid === '') return $this->errorResponse('userId obrigatorio.', 422);
        $limit = max(-1, (int) ($request->body['max_projects'] ?? 0));
        $this->service->setUserProjectLimit($uid, $limit);
        return Response::json(['saved' => true, 'user_id' => $uid, 'max_projects' => $limit]);
    }

    // ── Terminal & Execution ──────────────────────────────────────────────

    public function run(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $file = trim((string) ($request->body['file'] ?? ''));
        $code = trim((string) ($request->body['code'] ?? ''));

        if ($file === '' && $code === '') {
            return $this->errorResponse('Informe "file" ou "code".', 422);
        }

        // ── Validação server-side no controller (independente do PhpExecutor) ──
        if ($code !== '') {
            $violation = $this->validateCodeTableAccess($code, $project['module_name']);
            if ($violation !== null) {
                return Response::json([
                    'output'      => '',
                    'errors'      => $violation,
                    'exit_code'   => 1,
                    'duration_ms' => 0,
                    'type'        => 'security_block',
                ]);
            }
        }

        try {
            $exec = new PhpExecutor(self::EXECUTOR_TIMEOUT);
            return Response::json($file !== ''
                ? $exec->runFile($project['module_name'], $file)
                : $exec->runCode($code, $project['module_name']));
        } catch (\Throwable) {
            return $this->executorErrorResponse();
        }
    }

    /**
     * Validação server-side de isolamento de tabelas no controller.
     * Roda ANTES do PhpExecutor — camada independente.
     */
    private function validateCodeTableAccess(string $code, string $moduleName): ?string
    {
        $prefix = strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($moduleName)) ?? $moduleName);

        $systemTables = [
            'usuarios', 'users', 'user',
            'access_tokens', 'refresh_tokens', 'token_blacklist',
            'audit_logs', 'email_history', 'email_throttle',
            'ide_projects', 'ide_user_limits',
            'migrations',
            'links', 'link_limites', 'link_cliques',
            'capabilities', 'module_capabilities',
            'threat_scores', 'rate_limits',
            'sessions', 'password_resets',
            'tarefas', 'notas', 'avisos',
        ];

        // Bloqueia new PDO()
        if (preg_match('/\bnew\s+PDO\s*\(/i', $code)) {
            return "[Segurança] Criação direta de conexão PDO não é permitida.";
        }

        // Bloqueia SHOW TABLES/DATABASES
        if (preg_match('/\bSHOW\s+(TABLES|DATABASES|COLUMNS|CREATE\s+TABLE)/i', $code)) {
            return "[Segurança] Comando SHOW bloqueado.";
        }

        // Bloqueia information_schema / pg_catalog
        if (preg_match('/\b(information_schema|pg_catalog|pg_tables)\b/i', $code)) {
            return "[Segurança] Acesso a metadados do banco bloqueado.";
        }

        // Verifica tabelas referenciadas
        $pattern = '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|TRUNCATE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';
        preg_match_all($pattern, $code, $matches);

        foreach ($matches[1] as $table) {
            $t = strtolower($table);
            if ($t === 'migrations' || $t === '') continue;

            if (in_array($t, $systemTables, true)) {
                return "[Segurança] Acesso proibido à tabela do sistema '{$table}'.";
            }

            if (str_contains($t, '_') && !str_starts_with($t, $prefix . '_') && $t !== $prefix) {
                return "[Segurança] Tabela '{$table}' não pertence ao módulo '{$moduleName}'.";
            }
        }

        return null;
    }

    public function debugFile(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $file = trim((string) ($request->body['file'] ?? ''));
        if ($file === '') return $this->errorResponse('Informe o arquivo.', 422);

        // ── Validação server-side: lê o arquivo e valida tabelas antes de executar ──
        $moduleName = $project['module_name'];
        $modulesBase = dirname(__DIR__, 3) . '/Modules';
        $fullPath = realpath($modulesBase . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . str_replace(['..', '\\'], ['', '/'], $file));
        if ($fullPath !== false && is_file($fullPath)) {
            $content = file_get_contents($fullPath);
            if ($content !== false) {
                $violation = $this->validateCodeTableAccess($content, $moduleName);
                if ($violation !== null) {
                    return Response::json([
                        'output' => '', 'errors' => $violation,
                        'exit_code' => 1, 'duration_ms' => 0, 'type' => 'security_block',
                    ]);
                }
            }
        }

        try {
            $breakLine = isset($request->body['break_line']) ? (int) $request->body['break_line'] : null;
            return Response::json((new PhpExecutor(self::EXECUTOR_TIMEOUT))->debug($project['module_name'], $file, $breakLine));
        } catch (\Throwable) {
            return $this->executorErrorResponse();
        }
    }

    public function terminal(Request $request): Response
    {
        [, $project, $err] = $this->requireProject($request);
        if ($err) return $err;

        $cmd  = trim((string) ($request->body['command'] ?? ''));
        $path = trim((string) ($request->body['path'] ?? ''));

        try {
            $exec = new PhpExecutor(self::EXECUTOR_TIMEOUT);
            return match ($cmd) {
                'ls', 'dir'   => Response::json($exec->listFiles($project['module_name'], $path)),
                'cat', 'type' => Response::json($exec->readFile($project['module_name'], $path)),
                default       => $this->errorResponse('Comando nao reconhecido.', 422),
            };
        } catch (\Throwable) {
            return $this->errorResponse('Erro interno.', 500);
        }
    }
}
