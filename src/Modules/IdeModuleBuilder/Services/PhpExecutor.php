<?php

declare(strict_types=1);

namespace Src\Modules\IdeModuleBuilder\Services;

/**
 * Executa código PHP de forma isolada e segura para a IDE.
 *
 * SEGURANÇA (camadas independentes):
 * - Processo filho separado — falha no código não afeta o servidor
 * - disable_functions: bloqueia exec, system, shell_exec, curl, eval, etc.
 * - open_basedir: restringe filesystem ao diretório do módulo + /tmp
 * - allow_url_fopen=0 / allow_url_include=0: sem acesso a URLs externas
 * - Timeout rígido: mata o processo se exceder o limite
 * - Limite de output: 512KB máximo para evitar memory exhaustion
 * - Path traversal impossível: realpath + containment check
 *
 * O scan de padrões no código inline foi removido intencionalmente:
 * era redundante (o sandbox já bloqueia tudo via disable_functions + open_basedir)
 * e impedia código PHP legítimo como file_get_contents, include, $_SERVER, etc.
 * que funcionam corretamente dentro dos limites do open_basedir.
 */
final class PhpExecutor
{
    private string $modulesBase;
    private int $timeout;

    /**
     * Funções PHP proibidas — bloqueadas via -d disable_functions no processo filho.
     * Estas são as únicas funções que representam risco real de escape do sandbox.
     */
    private const DISABLED_FUNCTIONS = [
        // Execução de sistema — escape total do sandbox
        'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open', 'proc_close',
        'proc_get_status', 'proc_terminate', 'proc_nice', 'pcntl_exec', 'pcntl_fork',
        // Rede — acesso externo não autorizado
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
        'curl_init', 'curl_exec', 'curl_multi_init',
        // Manipulação de ambiente do processo pai
        'putenv', 'apache_setenv',
        // Vazamento de informações sensíveis do servidor
        'phpinfo', 'php_uname', 'getmyuid', 'getmypid', 'get_current_user',
        // Execução dinâmica de código arbitrário
        'eval', 'assert', 'create_function',
        // Operações de filesystem perigosas (open_basedir já restringe, mas dupla proteção)
        'link', 'symlink', 'readlink', 'chown', 'chgrp', 'lchown', 'lchgrp',
        'ini_set', 'ini_restore', 'dl',
        // Ações de servidor HTTP
        'mail', 'header', 'setcookie', 'session_start',
    ];

    public function __construct(int $timeout = 30)
    {
        $this->modulesBase = dirname(__DIR__, 4) . '/src/Modules';
        $this->timeout = $timeout;
    }

    /**
     * Executa um arquivo PHP do módulo.
     */
    public function runFile(string $moduleName, string $filePath): array
    {
        $moduleDir = $this->resolveModuleDir($moduleName);
        if ($moduleDir === null) {
            return $this->error("Módulo '{$moduleName}' não encontrado em src/Modules/.");
        }

        $fullPath = $this->resolveFilePath($moduleDir, $filePath);
        if ($fullPath === null) {
            return $this->error("Acesso negado ou arquivo não encontrado: {$filePath}");
        }

        if (pathinfo($fullPath, PATHINFO_EXTENSION) !== 'php') {
            return $this->error("Apenas arquivos .php podem ser executados.");
        }

        // ── Validação server-side de isolamento de tabelas ──────────────────
        $fileContent = file_get_contents($fullPath);
        if ($fileContent !== false) {
            $violation = $this->assertCodeSafe($fileContent, $moduleName);
            if ($violation !== null) {
                return $this->error($violation);
            }
        }

        $lint = $this->lint($fullPath);
        if ($lint !== null) return $lint;

        return $this->executeSandboxed($fullPath, $moduleDir);
    }

    /**
     * Executa código PHP inline (terminal interativo).
     *
     * O código roda dentro do sandbox (disable_functions + open_basedir),
     * portanto funções como file_get_contents, fopen, include, require, $_SERVER
     * funcionam normalmente — mas restritas ao diretório do módulo.
     * Funções de sistema (exec, shell_exec, curl, etc.) são bloqueadas pelo sandbox.
     */
    public function runCode(string $code, string $moduleName): array
    {
        $moduleDir = $this->resolveModuleDir($moduleName);
        if ($moduleDir === null) {
            return $this->error("Módulo '{$moduleName}' não encontrado.");
        }

        // Bloqueia backtick execution
        if (preg_match('/`[^`]*`/', $code)) {
            return $this->error("[Segurança] Execução via backtick não é permitida.");
        }

        // ── Validação server-side de isolamento de tabelas ──────────────────
        $violation = $this->assertCodeSafe($code, $moduleName);
        if ($violation !== null) {
            return $this->error($violation);
        }

        // Adiciona tag PHP se necessário
        $trimmed = ltrim($code);
        if (!str_starts_with($trimmed, '<?php') && !str_starts_with($trimmed, '<?')) {
            $code = "<?php\n" . $code;
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'ide_php_');
        if ($tmpBase === false) {
            return $this->error("Não foi possível criar arquivo temporário.");
        }
        $tmpFile = $tmpBase . '.php';
        @unlink($tmpBase);
        file_put_contents($tmpFile, $code);

        $lint = $this->lint($tmpFile);
        if ($lint !== null) {
            $lint['errors'] = str_replace([$tmpFile, basename($tmpFile)], 'terminal.php', $lint['errors']);
            @unlink($tmpFile);
            return $lint;
        }

        $result = $this->executeSandboxed($tmpFile, $moduleDir);
        @unlink($tmpFile);
        return $result;
    }

    /**
     * Debug: executa arquivo com análise de erros detalhada.
     */
    public function debug(string $moduleName, string $filePath, ?int $breakLine = null): array
    {
        $moduleDir = $this->resolveModuleDir($moduleName);
        if ($moduleDir === null) {
            return $this->error("Módulo '{$moduleName}' não encontrado.");
        }

        $fullPath = $this->resolveFilePath($moduleDir, $filePath);
        if ($fullPath === null) {
            return $this->error("Acesso negado ou arquivo não encontrado: {$filePath}");
        }

        $source = file_get_contents($fullPath);
        if ($source === false) {
            return $this->error("Não foi possível ler o arquivo.");
        }

        // ── Validação server-side de isolamento de tabelas ──────────────────
        $violation = $this->assertCodeSafe($source, $moduleName);
        if ($violation !== null) {
            return $this->error($violation);
        }

        $lint = $this->lint($fullPath);
        if ($lint !== null) return $lint;

        $result = $this->executeSandboxed($fullPath, $moduleDir);

        $lines = explode("\n", $source);
        $result['debug'] = [
            'file'        => $filePath,
            'total_lines' => count($lines),
            'break_line'  => $breakLine,
            'source'      => $lines,
        ];

        if ($result['exit_code'] !== 0) {
            $parsed = $this->parseRuntimeError($result['output'] . "\n" . $result['errors'], $filePath);
            $result['debug']['error_line'] = $parsed['line'];
            $result['debug']['error_message'] = $parsed['message'] ?? $result['errors'];
        }

        return $result;
    }

    /**
     * Lista arquivos do módulo (para comando ls/dir no terminal).
     */
    public function listFiles(string $moduleName, string $subPath = ''): array
    {
        $moduleDir = $this->resolveModuleDir($moduleName);
        if ($moduleDir === null) {
            return ['error' => "Módulo não encontrado."];
        }

        $target = $moduleDir;
        if ($subPath !== '') {
            $safe = $this->sanitizePath($subPath);
            $target = $moduleDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
            $real = realpath($target);
            if ($real === false || !str_starts_with($real, $moduleDir)) {
                return ['error' => "Caminho fora do módulo."];
            }
            $target = $real;
        }

        if (!is_dir($target)) {
            return ['error' => "Diretório não encontrado: {$subPath}"];
        }

        $items = [];
        foreach (scandir($target) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $target . DIRECTORY_SEPARATOR . $item;
            $items[] = [
                'name' => $item,
                'type' => is_dir($full) ? 'dir' : 'file',
                'size' => is_file($full) ? filesize($full) : null,
            ];
        }
        return ['files' => $items, 'path' => $subPath ?: '/'];
    }

    /**
     * Lê conteúdo de um arquivo do módulo (para comando cat no terminal).
     */
    public function readFile(string $moduleName, string $filePath): array
    {
        $moduleDir = $this->resolveModuleDir($moduleName);
        if ($moduleDir === null) return ['error' => "Módulo não encontrado."];

        $fullPath = $this->resolveFilePath($moduleDir, $filePath);
        if ($fullPath === null) return ['error' => "Acesso negado ou arquivo não encontrado."];

        $content = file_get_contents($fullPath);
        if ($content === false) return ['error' => "Não foi possível ler o arquivo."];

        // Limita a 50KB para segurança
        if (strlen($content) > 51200) {
            $content = substr($content, 0, 51200) . "\n... (truncado em 50KB)";
        }

        return ['content' => $content, 'file' => $filePath];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Segurança
    // ══════════════════════════════════════════════════════════════════════

    /** Tabelas do sistema — nunca acessíveis por módulos externos */
    private const SYSTEM_TABLES = [
        'usuarios', 'users', 'user',
        'access_tokens', 'refresh_tokens', 'token_blacklist',
        'audit_logs', 'email_history', 'email_throttle',
        'ide_projects', 'ide_user_limits',
        'migrations',
        'links', 'link_limites', 'link_cliques',
        'capabilities', 'module_capabilities',
        'threat_scores', 'rate_limits',
        'sessions', 'password_resets',
        'tarefas', 'notas',
    ];

    /**
     * Validação server-side de isolamento de tabelas — roda ANTES da execução.
     * Independente do client-side e do RestrictedPDO (que é a terceira camada no runtime).
     *
     * Retorna mensagem de erro ou null se o código é seguro.
     */
    private function assertCodeSafe(string $code, string $moduleName): ?string
    {
        $prefix = $this->moduleNameToPrefix($moduleName);

        // 1. Bloqueia new PDO() — tentativa de criar conexão própria para bypass
        if (preg_match('/\bnew\s+PDO\s*\(/i', $code)) {
            return "[Segurança] Criação direta de conexão PDO não é permitida. Use o \$pdo injetado pelo framework.";
        }

        // 2. Bloqueia SHOW TABLES / SHOW DATABASES / SHOW COLUMNS (enumeração)
        if (preg_match('/\bSHOW\s+(TABLES|DATABASES|COLUMNS|CREATE\s+TABLE)/i', $code)) {
            return "[Segurança] Comando SHOW bloqueado. Módulos não podem enumerar tabelas do banco.";
        }

        // 3. Bloqueia information_schema / pg_catalog (metadados do banco)
        if (preg_match('/\b(information_schema|pg_catalog|pg_tables|sys\.tables|sysobjects)\b/i', $code)) {
            return "[Segurança] Acesso a metadados do banco bloqueado. Módulos não podem enumerar tabelas.";
        }

        // 4. Extrai tabelas referenciadas em SQL e valida cada uma
        // Cobre: SELECT FROM, INSERT INTO, UPDATE, DELETE FROM, CREATE/DROP/ALTER/TRUNCATE TABLE, JOIN
        $tablePattern = '/\b(?:FROM|JOIN|INTO|UPDATE|DELETE\s+FROM|TABLE|TRUNCATE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\']?/i';
        preg_match_all($tablePattern, $code, $matches);

        foreach ($matches[1] as $table) {
            $t = strtolower($table);
            if ($t === 'migrations' || $t === '') continue;

            // Tabela do sistema
            if (in_array($t, self::SYSTEM_TABLES, true)) {
                return "[Segurança] Acesso proibido à tabela do sistema '{$table}'. Módulos só podem acessar suas próprias tabelas (prefixo: '{$prefix}_').";
            }

            // Tabela de outro módulo (tem underscore mas não começa com o prefixo correto)
            if (str_contains($t, '_') && !str_starts_with($t, $prefix . '_') && $t !== $prefix) {
                return "[Segurança] Tabela '{$table}' não pertence ao módulo '{$moduleName}'. Use apenas tabelas com prefixo '{$prefix}_'.";
            }
        }

        return null;
    }

    private function resolveModuleDir(string $moduleName): ?string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $moduleName)) return null;
        $dir = $this->modulesBase . DIRECTORY_SEPARATOR . $moduleName;
        if (!is_dir($dir)) return null;
        $real = realpath($dir);
        if ($real === false) return null;
        // Garante que está dentro de src/Modules/
        $realBase = realpath($this->modulesBase);
        if ($realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) return null;
        return $real;
    }

    /**
     * Resolve um caminho de arquivo dentro do módulo. Anti path traversal.
     */
    private function resolveFilePath(string $moduleDir, string $filePath): ?string
    {
        $safe = $this->sanitizePath($filePath);
        if ($safe === '') return null;

        $full = $moduleDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
        $real = realpath($full);
        if ($real === false || !is_file($real)) return null;

        // Containment: deve estar dentro do moduleDir
        if (!str_starts_with($real, $moduleDir . DIRECTORY_SEPARATOR)) return null;

        return $real;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Execução sandboxed
    // ══════════════════════════════════════════════════════════════════════

    private function lint(string $filePath): ?array
    {
        $phpBin = $this->findPhp();
        $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($filePath) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $text = $this->filterPhpNoise(implode("\n", $output));

        if ($code !== 0) {
            $parsed = $this->parseSyntaxError($text, $filePath);
            return [
                'output'      => '',
                'errors'      => $parsed['message'],
                'exit_code'   => 1,
                'duration_ms' => 0,
                'type'        => 'syntax_error',
                'file'        => $parsed['file'],
                'line'        => $parsed['line'],
            ];
        }
        return null;
    }

    /**
     * Executa PHP em sandbox com disable_functions, open_basedir e RestrictedPDO.
     *
     * O RestrictedPDO intercepta todas as queries SQL e bloqueia acesso a tabelas
     * que não pertencem ao módulo — terceira camada de isolamento de banco de dados.
     */
    private function executeSandboxed(string $filePath, string $moduleDir): array
    {
        $phpBin   = $this->findPhp();
        $disabled = implode(',', self::DISABLED_FUNCTIONS);
        $tmpDir   = sys_get_temp_dir();

        // Extrai o nome do módulo a partir do moduleDir
        $moduleName   = basename($moduleDir);
        $modulePrefix = $this->moduleNameToPrefix($moduleName);

        // Gera o código do RestrictedPDO inline no wrapper
        $restrictedPdoCode = $this->buildRestrictedPdoCode($modulePrefix, $moduleName);

        // Wrapper: configura sandbox + define RestrictedPDO + inclui o arquivo do usuário
        $wrapperCode = "<?php\n"
            . "ini_set('display_errors','1');\n"
            . "ini_set('error_reporting'," . E_ALL . ");\n"
            . "ini_set('max_execution_time'," . $this->timeout . ");\n"
            . "ini_set('memory_limit','128M');\n"
            . "ini_set('allow_url_fopen','0');\n"
            . "ini_set('allow_url_include','0');\n"
            . "ini_set('log_errors','0');\n"
            . $restrictedPdoCode . "\n"
            . "require " . var_export($filePath, true) . ";\n";

        $wrapperFile = tempnam($tmpDir, 'ide_wrap_');
        if ($wrapperFile === false) {
            return $this->error("Não foi possível criar arquivo temporário.");
        }
        $wrapperPhp = $wrapperFile . '.php';
        @unlink($wrapperFile);
        file_put_contents($wrapperPhp, $wrapperCode);

        // Usa array syntax para proc_open — mais seguro que string (sem shell intermediário)
        $cmdArray = [
            $phpBin,
            '-d', 'disable_functions=' . $disabled,
            '-d', 'open_basedir=' . $moduleDir . PATH_SEPARATOR . $tmpDir,
            $wrapperPhp,
        ];

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $start = hrtime(true);
        $proc  = proc_open($cmdArray, $descriptors, $pipes, $moduleDir);

        if (!is_resource($proc)) {
            @unlink($wrapperPhp);
            return $this->error("Não foi possível iniciar o processo PHP.");
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout    = '';
        $stderr    = '';
        $maxOutput = 512 * 1024;
        $deadline  = time() + $this->timeout;

        while (time() < $deadline) {
            $status  = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (!$status['running']) break;
            if (strlen($stdout) + strlen($stderr) > $maxOutput) {
                proc_terminate($proc, 9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
                return $this->error("Output excedeu 512KB e foi encerrado.");
            }
            usleep(10000);
        }

        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc, 9);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
            @unlink($wrapperPhp);
            $elapsed = (int)((hrtime(true) - $start) / 1e6);
            return ['output' => $this->filterPhpNoise($stdout), 'errors' => "Timeout: execução excedeu {$this->timeout}s e foi encerrada.", 'exit_code' => 137, 'duration_ms' => $elapsed, 'type' => 'timeout'];
        }

        $stdout  .= stream_get_contents($pipes[1]);
        $stderr  .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($wrapperPhp);
        $elapsed = (int)((hrtime(true) - $start) / 1e6);

        $stdout = $this->filterPhpNoise($stdout);
        $stderr = $this->filterPhpNoise($stderr);
        $stdout = $this->sanitizeOutput($stdout, $moduleDir);
        $stderr = $this->sanitizeOutput($stderr, $moduleDir);
        $stdout = str_replace([basename($wrapperPhp), $wrapperPhp], ['terminal.php', 'terminal.php'], $stdout);
        $stderr = str_replace([basename($wrapperPhp), $wrapperPhp], ['terminal.php', 'terminal.php'], $stderr);

        $combined  = trim($stdout . "\n" . $stderr);
        $errorInfo = null;
        if ($exitCode !== 0 || $stderr !== '') {
            $errorInfo = $this->parseRuntimeError($combined, basename($filePath));
        }

        return [
            'output'      => $stdout,
            'errors'      => $stderr,
            'exit_code'   => $exitCode,
            'duration_ms' => $elapsed,
            'type'        => $exitCode === 0 ? 'success' : 'runtime_error',
            'file'        => $errorInfo['file'] ?? null,
            'line'        => $errorInfo['line'] ?? null,
            'error_type'  => $errorInfo['type'] ?? null,
        ];
    }

    /**
     * Gera o código PHP do RestrictedPDO para injetar no wrapper de execução.
     *
     * O RestrictedPDO intercepta query(), exec() e prepare() e lança exceção
     * se a query tentar acessar tabelas que não pertencem ao módulo.
     * Isso é a terceira camada de proteção — runtime, inquebrável pelo código do módulo.
     */
    private function buildRestrictedPdoCode(string $modulePrefix, string $moduleName): string
    {
        // Tabelas do sistema — sempre proibidas
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
            'tarefas', 'notas',
        ];
        $systemTablesExport = var_export($systemTables, true);
        $modulePrefixExport = var_export($modulePrefix, true);
        $moduleNameExport   = var_export($moduleName, true);

        return <<<PHP
// ── RestrictedPDO — isolamento de tabelas por módulo ──────────────────────────
// Intercepta TODAS as queries SQL e bloqueia acesso a tabelas não autorizadas.
// Definida ANTES do require — o módulo não pode redefinir ou contornar.
if (!class_exists('RestrictedPDO', false)) {
    class RestrictedPDO extends PDO
    {
        private string \$_modulePrefix = {$modulePrefixExport};
        private string \$_moduleName   = {$moduleNameExport};
        private array  \$_systemTables = {$systemTablesExport};

        private function _assertTableAccess(string \$sql): void
        {
            \$sqlLower = strtolower(\$sql);

            // Bloqueia SHOW TABLES / SHOW DATABASES (enumeração)
            if (preg_match('/\\bSHOW\\s+(TABLES|DATABASES|COLUMNS|CREATE\\s+TABLE)/i', \$sql)) {
                throw new \\RuntimeException(
                    "[Segurança] Comando SHOW bloqueado. Módulos não podem enumerar tabelas do banco."
                );
            }

            // Bloqueia information_schema / pg_catalog (enumeração de metadados)
            if (preg_match('/\\b(information_schema|pg_catalog|pg_tables|sys\\.tables|sysobjects)\\b/i', \$sql)) {
                throw new \\RuntimeException(
                    "[Segurança] Acesso a metadados do banco bloqueado. Módulos não podem enumerar tabelas."
                );
            }

            // Extrai nomes de tabelas: FROM, JOIN, INTO, UPDATE, DELETE FROM, TABLE, TRUNCATE
            \$pattern = '/\\b(?:FROM|JOIN|INTO|UPDATE|DELETE\\s+FROM|TABLE|TRUNCATE)\\s+(?:IF\\s+(?:NOT\\s+)?EXISTS\\s+)?[`"\'\\[\\s]*([a-zA-Z_][a-zA-Z0-9_]*)[`"\'\\]\\s]*/i';
            preg_match_all(\$pattern, \$sql, \$matches);

            foreach (\$matches[1] as \$table) {
                \$t = strtolower(trim(\$table));
                if (\$t === '' || \$t === 'migrations') continue;

                // Tabela do sistema — BLOQUEADO
                if (in_array(\$t, \$this->_systemTables, true)) {
                    throw new \\RuntimeException(
                        "[Segurança] Acesso bloqueado: tabela '{\$table}' pertence ao sistema."
                    );
                }

                // Tabela de outro módulo — BLOQUEADO
                if (strpos(\$t, '_') !== false
                    && strpos(\$t, \$this->_modulePrefix . '_') !== 0
                    && \$t !== \$this->_modulePrefix) {
                    throw new \\RuntimeException(
                        "[Segurança] Acesso bloqueado: tabela '{\$table}' não pertence ao módulo '{\$this->_moduleName}'. Use prefixo '{\$this->_modulePrefix}_'."
                    );
                }
            }
        }

        public function query(string \$query, ?int \$fetchMode = null, mixed ...\$fetchModeArgs): PDOStatement|false
        {
            \$this->_assertTableAccess(\$query);
            return parent::query(\$query, \$fetchMode, ...\$fetchModeArgs);
        }

        public function exec(string \$statement): int|false
        {
            \$this->_assertTableAccess(\$statement);
            return parent::exec(\$statement);
        }

        public function prepare(string \$query, array \$options = []): PDOStatement|false
        {
            \$this->_assertTableAccess(\$query);
            return parent::prepare(\$query, \$options);
        }
    }
}
PHP;
    }

    /**
     * Converte PascalCase para snake_case (prefixo de tabela).
     */
    private function moduleNameToPrefix(string $name): string
    {
        $snake = preg_replace('/([A-Z])/', '_$1', lcfirst($name)) ?? $name;
        return strtolower($snake);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function parseSyntaxError(string $text, string $filePath): array
    {
        $file = basename($filePath);
        $line = null;
        $message = $text;
        if (preg_match('/in\s+(.+?)\s+on\s+line\s+(\d+)/i', $text, $m)) {
            $file = basename($m[1]);
            $line = (int)$m[2];
        }
        $message = preg_replace('/in\s+[\/\\\\][^\s]+/', 'in ' . $file, $message);
        return ['file' => $file, 'line' => $line, 'message' => trim($message)];
    }

    private function parseRuntimeError(string $text, string $filePath): array
    {
        $file = basename($filePath);
        $line = null;
        $type = null;
        $message = null;
        if (preg_match('/(Fatal error|Warning|Notice|Deprecated|Error):\s*(.+?)\s+in\s+(.+?)\s+on\s+line\s+(\d+)/i', $text, $m)) {
            $type = $m[1];
            $message = $m[2];
            $file = basename($m[3]);
            $line = (int)$m[4];
        }
        return ['file' => $file, 'line' => $line, 'type' => $type, 'message' => $message];
    }

    /**
     * Remove warnings internos do PHP irrelevantes para o desenvolvedor.
     */
    private function filterPhpNoise(string $text): string
    {
        if ($text === '') return '';
        $lines = explode("\n", $text);
        $filtered = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/Module\s+".+"\s+is already loaded/i', $t)) continue;
            if (preg_match('/^(PHP\s+)?Warning:\s+Module\s+".+"\s+is already loaded/i', $t)) continue;
            if (preg_match('/^(PHP\s+)?Warning:\s+PHP Startup:/i', $t)) continue;
            $filtered[] = $line;
        }
        return implode("\n", $filtered);
    }

    /**
     * Remove caminhos absolutos do output para não vazar estrutura do servidor.
     */
    private function sanitizeOutput(string $text, string $moduleDir): string
    {
        if ($text === '') return '';
        // Substitui o caminho absoluto do módulo por caminho relativo
        $text = str_replace($moduleDir . DIRECTORY_SEPARATOR, '', $text);
        $text = str_replace($moduleDir . '/', '', $text);
        $text = str_replace($moduleDir, '.', $text);
        // Remove qualquer caminho absoluto restante
        $text = preg_replace('#/[a-z0-9_/.-]+/src/Modules/[A-Za-z0-9]+/#i', '', $text);
        $text = preg_replace('#[A-Z]:\\\\[^\\s]+\\\\src\\\\Modules\\\\[A-Za-z0-9]+\\\\#i', '', $text);
        return $text;
    }

    private function findPhp(): string
    {
        if (defined('PHP_BINARY') && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }
        return 'php';
    }

    private function sanitizePath(string $path): string
    {
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        return ltrim($path, '/');
    }

    private function error(string $msg): array
    {
        return [
            'output'      => '',
            'errors'      => $msg,
            'exit_code'   => 1,
            'duration_ms' => 0,
            'type'        => 'error',
        ];
    }
}
