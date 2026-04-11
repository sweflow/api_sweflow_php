<?php

declare(strict_types=1);

namespace Src\Modules\IdeModuleBuilder\Services;

/**
 * ModuleAnalyzer — Analisa estaticamente os arquivos de um projeto IDE
 * antes do deploy, detectando problemas que impediriam o módulo de funcionar.
 *
 * Categorias de problemas:
 *   - BLOQUEANTE (severity: error)   → impede o deploy
 *   - AVISO      (severity: warning) → deploy permitido, mas recomenda correção
 *   - INFO       (severity: info)    → sugestão de melhoria
 */
final class ModuleAnalyzer
{
    /** @var array<array{file:string,line:int,severity:string,code:string,message:string,suggestion:string}> */
    private array $issues = [];

    private string $moduleName;
    /** @var array<string,string> path => content */
    private array $files;

    public function __construct(string $moduleName, array $files)
    {
        $this->moduleName = $moduleName;
        $this->files      = $files;
    }

    /**
     * Executa a análise completa e retorna o relatório.
     */
    public function analyze(): array
    {
        $this->issues = [];

        // Filtra apenas arquivos PHP (ignora .gitkeep, .md, etc.)
        $phpFiles = array_filter(
            $this->files,
            fn($path) => str_ends_with($path, '.php'),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($phpFiles) && empty($this->files)) {
            $this->addIssue('', 0, 'error', 'EMPTY_PROJECT',
                'O projeto não tem nenhum arquivo.',
                'Crie pelo menos um arquivo PHP com as rotas do módulo.'
            );
            return $this->buildReport();
        }

        foreach ($phpFiles as $path => $content) {
            $this->analyzeFile($path, $content);
        }

        // Verificações de estrutura do módulo
        $this->checkModuleStructure();

        // Verificações de segurança
        $this->checkSecurity($phpFiles);

        return $this->buildReport();
    }

    // ── Análise por arquivo ───────────────────────────────────────────────

    private function analyzeFile(string $path, string $content): void
    {
        // 1. Sintaxe PHP via php -l (se disponível)
        $this->checkPhpSyntax($path, $content);

        // 2. Namespace correto
        $this->checkNamespace($path, $content);

        // 3. Verificações específicas por tipo de arquivo
        if (str_contains($path, 'Routes/')) {
            $this->checkRoutesFile($path, $content);
        }
        if (str_contains($path, 'Database/Migrations/')) {
            $this->checkMigrationFile($path, $content);
        }
        if (str_contains($path, 'Controllers/')) {
            $this->checkControllerFile($path, $content);
        }
        if ($path === 'Database/connection.php') {
            $this->checkConnectionFile($path, $content);
        }

        // 4. Padrões perigosos
        $this->checkDangerousPatterns($path, $content);
    }

    // ── Verificação de sintaxe PHP ────────────────────────────────────────

    private function checkPhpSyntax(string $path, string $content): void
    {
        // Escreve em arquivo temporário e executa php -l
        $tmp = tempnam(sys_get_temp_dir(), 'vupi_lint_');
        if ($tmp === false) return;

        try {
            file_put_contents($tmp, $content);
            $output = [];
            $code   = 0;
            exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);

            if ($code !== 0) {
                $raw = implode(' ', $output);
                // Substitui o caminho temporário pelo caminho real do arquivo
                $msg = str_replace($tmp, $path, $raw);
                // Extrai número de linha se presente
                $line = 0;
                if (preg_match('/on line (\d+)/i', $msg, $m)) {
                    $line = (int) $m[1];
                }
                // Limpa a mensagem
                $msg = preg_replace('/^(PHP\s+)?Parse error:\s*/i', '', $msg) ?? $msg;
                $msg = preg_replace('/\s+in\s+.+\.php\s+on\s+line\s+\d+/i', '', $msg) ?? $msg;
                $msg = trim($msg);

                $this->addIssue($path, $line, 'error', 'SYNTAX_ERROR',
                    "Erro de sintaxe PHP: {$msg}",
                    'Corrija o erro de sintaxe antes de publicar. Verifique parênteses, chaves e ponto-e-vírgula.'
                );
            }
        } finally {
            @unlink($tmp);
        }
    }

    // ── Verificação de namespace ──────────────────────────────────────────

    private function checkNamespace(string $path, string $content): void
    {
        // Ignora arquivos que não são classes (routes, connection, etc.)
        $skipPaths = ['Routes/', 'Database/connection.php', 'Database/Migrations/', 'Database/Seeders/'];
        foreach ($skipPaths as $skip) {
            if (str_contains($path, $skip)) return;
        }

        // Deve ter declaração de namespace
        if (!preg_match('/^\s*namespace\s+([A-Za-z\\\\]+)\s*;/m', $content, $m)) {
            $this->addIssue($path, 1, 'warning', 'MISSING_NAMESPACE',
                'Arquivo PHP sem declaração de namespace.',
                "Adicione: namespace Src\\Modules\\{$this->moduleName}\\..."
            );
            return;
        }

        $declared = $m[1];
        $expected = "Src\\Modules\\{$this->moduleName}";

        // Namespace deve começar com o namespace correto do módulo
        if (!str_starts_with($declared, $expected)) {
            $this->addIssue($path, 1, 'error', 'WRONG_NAMESPACE',
                "Namespace incorreto: '{$declared}'. Esperado: '{$expected}\\...'",
                "O namespace deve começar com 'Src\\Modules\\{$this->moduleName}'. " .
                "Namespaces do kernel (Src\\Kernel\\*) são proibidos para módulos externos."
            );
        }

        // Namespace do kernel é proibido
        if (str_starts_with($declared, 'Src\\Kernel\\')) {
            $this->addIssue($path, 1, 'error', 'FORBIDDEN_NAMESPACE',
                "Namespace proibido: '{$declared}'. Módulos não podem usar o namespace do kernel.",
                "Use apenas 'Src\\Modules\\{$this->moduleName}\\...' como namespace."
            );
        }
    }

    // ── Verificação de arquivo de rotas ───────────────────────────────────

    private function checkRoutesFile(string $path, string $content): void
    {
        // Deve ter pelo menos uma rota registrada
        if (!preg_match('/\$router->(get|post|put|patch|delete)\s*\(/i', $content)) {
            $this->addIssue($path, 0, 'warning', 'NO_ROUTES',
                'Arquivo de rotas sem nenhuma rota registrada.',
                'Adicione pelo menos uma rota: $router->get(\'/api/...\', [Controller::class, \'method\']);'
            );
            return;
        }

        // Verifica rotas que tentam usar prefixos reservados
        $reservedPrefixes = \Src\Kernel\Nucleo\ModuleGuard::reservedPrefixes();
        preg_match_all('/\$router->\w+\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches);
        foreach ($matches[1] as $uri) {
            foreach ($reservedPrefixes as $prefix) {
                if (str_starts_with(strtolower($uri), strtolower($prefix))) {
                    $line = $this->findLineOf($content, $uri);
                    $this->addIssue($path, $line, 'error', 'RESERVED_URI',
                        "Rota '{$uri}' usa um prefixo reservado pelo sistema: '{$prefix}'",
                        "Escolha um prefixo diferente para suas rotas. Prefixos reservados: " .
                        implode(', ', array_slice($reservedPrefixes, 0, 5)) . '...'
                    );
                }
            }
        }

        // Rotas sem middleware de autenticação em métodos que modificam dados
        // Usa regex que captura a linha inteira para não perder middlewares em arrays aninhados
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            // Verifica se a linha tem uma rota de escrita
            if (!preg_match('/\$router->(post|put|patch|delete)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $line, $routeMatch)) {
                continue;
            }

            $method = strtoupper($routeMatch[1]);
            $uri    = $routeMatch[2];

            // Coleta a chamada completa (pode ocupar múltiplas linhas)
            $fullCall = '';
            $depth    = 0;
            $started  = false;
            for ($i = $lineNum; $i < count($lines) && $i < $lineNum + 10; $i++) {
                $fullCall .= $lines[$i] . "\n";
                foreach (str_split($lines[$i]) as $ch) {
                    if ($ch === '(') { $depth++; $started = true; }
                    if ($ch === ')') { $depth--; }
                }
                if ($started && $depth <= 0) break;
            }

            // Considera protegida se:
            // 1. Contém "Middleware" na chamada
            // 2. Referencia variável de middleware ($protected, $userProtected, $adminProtected, etc.)
            // 3. Tem 3º argumento (qualquer array após o handler)
            $isProtected =
                str_contains($fullCall, 'Middleware') ||
                preg_match('/\$\w*[Pp]rotected\b/', $fullCall) ||
                preg_match('/\$\w*[Mm]iddleware\b/', $fullCall) ||
                preg_match('/\$\w*[Rr]ate[Ll]imit\b/', $fullCall) ||
                preg_match('/\$\w*[Cc]ircuit\b/', $fullCall) ||
                preg_match('/\$\w*[Aa]uth\b/', $fullCall) ||
                // Tem 3º argumento após o handler [Controller::class, 'method']
                preg_match('/\[[\w\\\\:\'", ]+\]\s*,\s*[\[\$]/', $fullCall);

            if (!$isProtected) {
                $this->addIssue($path, $lineNum + 1, 'warning', 'UNPROTECTED_WRITE_ROUTE',
                    "Rota {$method} '{$uri}' sem middleware de autenticação.",
                    'Considere proteger rotas de escrita com AuthHybridMiddleware ou AdminOnlyMiddleware.'
                );
            }
        }
    }

    // ── Verificação de migration ──────────────────────────────────────────

    private function checkMigrationFile(string $path, string $content): void
    {
        // Deve retornar array com 'up' e 'down'
        if (!str_contains($content, "'up'") && !str_contains($content, '"up"')) {
            $this->addIssue($path, 0, 'error', 'MISSING_UP',
                "Migration sem função 'up'.",
                "A migration deve retornar: return ['up' => function(PDO \$pdo): void { ... }, 'down' => ...];"
            );
        }
        if (!str_contains($content, "'down'") && !str_contains($content, '"down"')) {
            $this->addIssue($path, 0, 'warning', 'MISSING_DOWN',
                "Migration sem função 'down' (rollback).",
                "Adicione 'down' para permitir reverter a migration: 'down' => function(PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS ...'); }"
            );
        }

        // Deve usar CREATE TABLE IF NOT EXISTS (idempotente)
        if (preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $content)) {
            $line = $this->findLineOf($content, 'CREATE TABLE');
            $this->addIssue($path, $line, 'warning', 'NON_IDEMPOTENT_MIGRATION',
                "Migration usa CREATE TABLE sem IF NOT EXISTS.",
                "Use 'CREATE TABLE IF NOT EXISTS' para tornar a migration idempotente e evitar erros ao re-executar."
            );
        }

        // Deve verificar o driver (pgsql vs mysql)
        if (!str_contains($content, 'ATTR_DRIVER_NAME') && !str_contains($content, 'pgsql')) {
            $this->addIssue($path, 0, 'info', 'NO_DRIVER_CHECK',
                "Migration não verifica o driver do banco de dados.",
                "Adicione verificação do driver para compatibilidade: \$driver = \$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); if (\$driver === 'pgsql') { ... } else { ... }"
            );
        }
    }

    // ── Verificação de controller ─────────────────────────────────────────

    private function checkControllerFile(string $path, string $content): void
    {
        // Métodos públicos devem retornar Response
        preg_match_all('/public\s+function\s+(\w+)\s*\([^)]*\)\s*(?::\s*(\w+))?/i', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $methodName = $match[1];
            if ($methodName === '__construct') continue;
            $returnType = $match[2] ?? '';
            if ($returnType !== '' && $returnType !== 'Response' && $returnType !== 'void') {
                $line = $this->findLineOf($content, "function {$methodName}");
                $this->addIssue($path, $line, 'warning', 'UNEXPECTED_RETURN_TYPE',
                    "Método público '{$methodName}' retorna '{$returnType}' em vez de 'Response'.",
                    "Controllers devem retornar Response::json() ou Response::html(). " .
                    "Exemplo: public function {$methodName}(Request \$request): Response"
                );
            }
        }

        // Uso de die/exit é proibido em controllers
        if (preg_match('/\b(die|exit)\s*\(/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($content, 0, (int)$m[0][1]), "\n") + 1;
            $this->addIssue($path, $line, 'error', 'DIE_EXIT_IN_CONTROLLER',
                "Uso de '{$m[1][0]}()' em controller. Isso interrompe o pipeline do framework.",
                "Substitua por 'return Response::json([...], 400);' ou lance uma DomainException."
            );
        }
    }

    // ── Verificação de connection.php ─────────────────────────────────────

    private function checkConnectionFile(string $path, string $content): void
    {
        $valid = ["'core'", '"core"', "'modules'", '"modules"', "'auto'", '"auto"'];
        $hasValid = false;
        foreach ($valid as $v) {
            if (str_contains($content, $v)) { $hasValid = true; break; }
        }
        if (!$hasValid) {
            $this->addIssue($path, 0, 'error', 'INVALID_CONNECTION',
                "Database/connection.php deve retornar 'core', 'modules' ou 'auto'.",
                "Conteúdo esperado: <?php return 'core'; // ou 'modules' ou 'auto'"
            );
        }
    }

    // ── Verificação de padrões perigosos ──────────────────────────────────

    private function checkDangerousPatterns(string $path, string $content): void
    {
        $dangerous = [
            // Execução de sistema — exclui $pdo->exec(), $stmt->exec(), ->exec() em geral
            ['pattern' => '/(?<!->)(?<!::)\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(/i',
             'code' => 'SHELL_EXECUTION',
             'msg'  => "Uso de função de execução de sistema: %s()",
             'sug'  => 'Funções de execução de sistema são proibidas em módulos. Remova ou substitua por lógica PHP pura.'],

            // Eval
            ['pattern' => '/\beval\s*\(/i',
             'code' => 'EVAL_USAGE',
             'msg'  => 'Uso de eval() detectado.',
             'sug'  => 'eval() é extremamente perigoso e proibido. Refatore o código sem eval().'],

            // Inclusão dinâmica de arquivos externos
            ['pattern' => '/\b(include|require)(_once)?\s*\(\s*\$(?!router|pdo|container)/i',
             'code' => 'DYNAMIC_INCLUDE',
             'msg'  => 'Inclusão dinâmica de arquivo via variável.',
             'sug'  => 'Evite include/require com variáveis. Use autoload do Composer.'],

            // Acesso direto a $_SERVER com dados sensíveis
            ['pattern' => '/\$_SERVER\s*\[\s*[\'"](?:PHP_AUTH_PW|HTTP_AUTHORIZATION)[\'"]]/i',
             'code' => 'SENSITIVE_SERVER_ACCESS',
             'msg'  => 'Acesso direto a dados sensíveis em $_SERVER.',
             'sug'  => 'Use os middlewares de autenticação do framework em vez de acessar $_SERVER diretamente.'],

            // Manipulação de headers diretamente
            ['pattern' => '/\bheader\s*\(/i',
             'code' => 'DIRECT_HEADER',
             'msg'  => 'Uso de header() diretamente.',
             'sug'  => 'Use Response::json() ou Response::html() em vez de header(). O framework gerencia os headers.'],

            // Acesso a arquivos fora do módulo
            ['pattern' => '/[\'"]\.\.\/\.\.\/\.\.\/(?:src|vendor|storage|\.env)/i',
             'code' => 'PATH_TRAVERSAL',
             'msg'  => 'Possível path traversal detectado.',
             'sug'  => 'Não acesse arquivos fora do seu módulo. Use apenas caminhos relativos ao módulo.'],

            // Acesso direto ao .env
            ['pattern' => '/[\'"]\.env[\'"]|file_get_contents.*\.env/i',
             'code' => 'ENV_FILE_ACCESS',
             'msg'  => 'Tentativa de acesso ao arquivo .env.',
             'sug'  => 'Use $_ENV[\'VARIAVEL\'] ou getenv(\'VARIAVEL\') para acessar variáveis de ambiente.'],

            // Uso de die/exit fora de controllers (já verificado lá)
            ['pattern' => '/\b(die|exit)\s*\(/i',
             'code' => 'DIE_EXIT',
             'msg'  => "Uso de %s() que interrompe o pipeline do framework.",
             'sug'  => 'Substitua por exceções ou retorno de Response.'],
        ];

        // Não verifica padrões perigosos em arquivos de rota (já verificado separadamente)
        if (str_contains($path, 'Controllers/')) {
            // Controllers já verificam die/exit separadamente
            $dangerous = array_filter($dangerous, fn($d) => $d['code'] !== 'DIE_EXIT');
        }

        foreach ($dangerous as $check) {
            if (preg_match($check['pattern'], $content, $m, PREG_OFFSET_CAPTURE)) {
                $offset = (int)($m[0][1] ?? 0);
                $line   = substr_count(substr($content, 0, $offset), "\n") + 1;
                $found  = $m[1][0] ?? $m[0][0];
                $msg    = str_contains($check['msg'], '%s') ? sprintf($check['msg'], $found) : $check['msg'];
                $this->addIssue($path, $line, 'error', $check['code'], $msg, $check['sug']);
            }
        }
    }

    // ── Verificação de estrutura do módulo ────────────────────────────────

    private function checkModuleStructure(): void
    {
        $paths = array_keys($this->files);

        // Deve ter pelo menos um arquivo de rotas
        $hasRoutes = false;
        foreach ($paths as $p) {
            if (str_contains($p, 'Routes/') && str_ends_with($p, '.php')) {
                $hasRoutes = true;
                break;
            }
        }
        if (!$hasRoutes) {
            $this->addIssue('', 0, 'error', 'NO_ROUTES_FILE',
                'Módulo sem arquivo de rotas.',
                "Crie o arquivo 'Routes/web.php' com as rotas do módulo. " .
                "Sem rotas, o módulo não terá endpoints acessíveis."
            );
        }

        // Verifica se arquivos .gitkeep estão sozinhos (pastas vazias)
        foreach ($paths as $p) {
            if (str_ends_with($p, '.gitkeep')) {
                $folder = dirname($p);
                $siblings = array_filter($paths, fn($x) => str_starts_with($x, $folder . '/') && $x !== $p);
                if (empty($siblings)) {
                    $this->addIssue($p, 0, 'info', 'EMPTY_FOLDER',
                        "Pasta '{$folder}' está vazia (contém apenas .gitkeep).",
                        'Adicione arquivos à pasta ou remova-a se não for necessária.'
                    );
                }
            }
        }

        // Verifica se controllers referenciados nas rotas existem no projeto
        foreach ($this->files as $path => $content) {
            if (!str_contains($path, 'Routes/')) continue;
            preg_match_all('/use\s+(Src\\\\Modules\\\\[A-Za-z\\\\]+Controller)\s*;/i', $content, $uses);
            foreach ($uses[1] as $fqcn) {
                // Converte FQCN para caminho relativo esperado
                $relative = str_replace('Src\\Modules\\' . $this->moduleName . '\\', '', $fqcn);
                $filePath = str_replace('\\', '/', $relative) . '.php';
                if (!isset($this->files[$filePath])) {
                    $line = $this->findLineOf($content, $fqcn);
                    $this->addIssue($path, $line, 'warning', 'MISSING_CONTROLLER',
                        "Controller '{$fqcn}' referenciado nas rotas não foi encontrado no projeto.",
                        "Crie o arquivo '{$filePath}' ou verifique se o nome está correto."
                    );
                }
            }
        }
    }

    // ── Verificação de segurança global ──────────────────────────────────

    private function checkSecurity(array $phpFiles): void
    {
        // Verifica se há SQL injection potencial (concatenação direta em queries)
        foreach ($phpFiles as $path => $content) {
            if (preg_match('/\$pdo->(query|exec)\s*\(\s*["\'].*?\$(?!pdo)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($content, 0, (int)$m[0][1]), "\n") + 1;
                $this->addIssue($path, $line, 'warning', 'POTENTIAL_SQL_INJECTION',
                    'Possível SQL injection: variável concatenada diretamente em query.',
                    'Use prepared statements: $stmt = $pdo->prepare("SELECT * FROM t WHERE id = ?"); $stmt->execute([$id]);'
                );
            }
        }

        // Verifica acesso a tabelas que não pertencem ao módulo
        $this->checkTableIsolation($phpFiles);
    }

    /**
     * Detecta queries SQL que referenciam tabelas do sistema ou de outros módulos.
     * Cobre SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, TRUNCATE, JOIN.
     * Também bloqueia tentativas de criar conexão PDO própria e acessar information_schema.
     */
    private function checkTableIsolation(array $phpFiles): void
    {
        $systemTables = [
            'usuarios', 'users', 'user',
            'access_tokens', 'refresh_tokens', 'token_blacklist',
            'audit_logs', 'email_history', 'email_throttle',
            'ide_projects', 'ide_user_limits',
            'migrations',
            'link_limites', 'link_cliques', 'links',
            'capabilities', 'module_capabilities',
            'threat_scores', 'rate_limits',
            'sessions', 'password_resets',
            'tarefas', 'notas', 'avisos',
        ];

        $modulePrefix = $this->toSnakeCase($this->moduleName);
        $ownTables    = $this->collectOwnTables();

        // Padrões SQL que referenciam tabelas
        $sqlTablePattern = '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|TRUNCATE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';

        foreach ($phpFiles as $path => $content) {
            if (str_contains($path, 'Database/Migrations/')) {
                // Nas migrations: valida que tabelas usam o prefixo correto
                $this->checkMigrationTablePrefix($path, $content, $modulePrefix);
                continue;
            }
            if (str_contains($path, 'Database/Seeders/')) {
                // Seeders: valida tabelas também
                $this->checkFileTableAccess($path, $content, $sqlTablePattern, $systemTables, $ownTables, $modulePrefix);
                continue;
            }

            // Bloqueia tentativa de criar conexão PDO própria (bypass do RestrictedPDO)
            if (preg_match('/\bnew\s+PDO\s*\(/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($content, 0, (int)$m[0][1]), "\n") + 1;
                $this->addIssue($path, $line, 'error', 'DIRECT_PDO_CONNECTION',
                    'Criação direta de conexão PDO não é permitida.',
                    'Use o $pdo injetado pelo framework via constructor injection. Criar conexão própria é proibido por segurança.'
                );
            }

            // Bloqueia acesso a information_schema / pg_catalog (enumeração de tabelas)
            if (preg_match('/\b(information_schema|pg_catalog|pg_tables|sys\.tables|sysobjects)\b/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($content, 0, (int)$m[0][1]), "\n") + 1;
                $this->addIssue($path, $line, 'error', 'SCHEMA_ENUMERATION',
                    "Acesso a '{$m[0][0]}' é proibido. Módulos não podem enumerar tabelas do banco.",
                    'Use apenas as tabelas do seu módulo. Não tente descobrir tabelas de outros módulos.'
                );
            }

            // Verifica tabelas referenciadas em queries
            $this->checkFileTableAccess($path, $content, $sqlTablePattern, $systemTables, $ownTables, $modulePrefix);
        }
    }

    /**
     * Verifica acesso a tabelas em um arquivo específico.
     */
    private function checkFileTableAccess(string $path, string $content, string $pattern, array $systemTables, array $ownTables, string $modulePrefix): void
    {
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $tableName = strtolower($match[0]);
            $offset    = (int) $match[1];
            $line      = substr_count(substr($content, 0, $offset), "\n") + 1;

            if ($tableName === 'migrations') continue;

            // Tabela do sistema
            if (in_array($tableName, $systemTables, true)) {
                $this->addIssue($path, $line, 'error', 'FORBIDDEN_TABLE_ACCESS',
                    "Acesso proibido à tabela do sistema '{$tableName}'.",
                    "Módulos não podem acessar tabelas do sistema. Use apenas tabelas com prefixo '{$modulePrefix}_'."
                );
                continue;
            }

            // Tabela do próprio módulo — OK
            if (in_array($tableName, $ownTables, true)) continue;
            if (str_starts_with($tableName, $modulePrefix . '_')) continue;
            if ($tableName === $modulePrefix) continue;

            // Tabela de outro módulo
            if (preg_match('/^[a-z][a-z0-9]*_/', $tableName)) {
                $this->addIssue($path, $line, 'error', 'CROSS_MODULE_TABLE_ACCESS',
                    "Acesso a tabela '{$tableName}' que não pertence ao módulo '{$this->moduleName}'.",
                    "Use apenas tabelas com prefixo '{$modulePrefix}_' (ex: {$modulePrefix}_itens)."
                );
            }
        }
    }

    /**
     * Valida que migrations só criam/alteram tabelas com o prefixo do módulo.
     */
    private function checkMigrationTablePrefix(string $path, string $content, string $modulePrefix): void
    {
        $ddlPattern = '/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';
        preg_match_all($ddlPattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $table  = $match[0];
            $tLower = strtolower($table);
            $line   = substr_count(substr($content, 0, (int)$match[1]), "\n") + 1;

            if (!str_starts_with($tLower, $modulePrefix . '_') && $tLower !== $modulePrefix) {
                $this->addIssue($path, $line, 'error', 'MIGRATION_WRONG_PREFIX',
                    "Tabela '{$table}' na migration não usa o prefixo obrigatório '{$modulePrefix}_'.",
                    "Renomeie para '{$modulePrefix}_{$table}'. Cada módulo só pode criar tabelas com seu próprio prefixo."
                );
            }
        }
    }

    /**
     * Coleta os nomes de tabelas declarados nas migrations do próprio módulo.
     */
    private function collectOwnTables(): array
    {
        $tables  = [];
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';

        foreach ($this->files as $path => $content) {
            if (!str_contains($path, 'Database/Migrations/')) continue;
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] as $table) {
                $tables[] = strtolower($table);
            }
        }

        return array_unique($tables);
    }

    /**
     * Converte PascalCase para snake_case.
     * Ex: MeuModulo → meu_modulo, LinkEncurtador → link_encurtador
     */
    private function toSnakeCase(string $name): string
    {
        $snake = preg_replace('/([A-Z])/', '_$1', lcfirst($name)) ?? $name;
        return strtolower($snake);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function addIssue(
        string $file,
        int    $line,
        string $severity,
        string $code,
        string $message,
        string $suggestion
    ): void {
        $this->issues[] = [
            'file'       => $file,
            'line'       => $line,
            'severity'   => $severity,   // error | warning | info
            'code'       => $code,
            'message'    => $message,
            'suggestion' => $suggestion,
        ];
    }

    private function findLineOf(string $content, string $needle): int
    {
        $pos = strpos($content, $needle);
        if ($pos === false) return 0;
        return substr_count(substr($content, 0, $pos), "\n") + 1;
    }

    private function buildReport(): array
    {
        $errors   = array_filter($this->issues, fn($i) => $i['severity'] === 'error');
        $warnings = array_filter($this->issues, fn($i) => $i['severity'] === 'warning');
        $infos    = array_filter($this->issues, fn($i) => $i['severity'] === 'info');

        $canDeploy = empty($errors);

        return [
            'can_deploy'    => $canDeploy,
            'total_issues'  => count($this->issues),
            'error_count'   => count($errors),
            'warning_count' => count($warnings),
            'info_count'    => count($infos),
            'issues'        => array_values($this->issues),
            'summary'       => $canDeploy
                ? (empty($warnings)
                    ? 'Módulo aprovado para publicação. Nenhum problema encontrado.'
                    : 'Módulo aprovado com ' . count($warnings) . ' aviso(s). Revise antes de publicar.')
                : count($errors) . ' erro(s) bloqueante(s) encontrado(s). Corrija antes de publicar.',
        ];
    }
}
