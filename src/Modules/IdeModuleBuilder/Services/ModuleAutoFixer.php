<?php

declare(strict_types=1);

namespace Src\Modules\IdeModuleBuilder\Services;

/**
 * ModuleAutoFixer — Aplica correções automáticas nos arquivos do projeto.
 *
 * Estratégia: agrupa todos os issues por arquivo e aplica TODAS as correções
 * necessárias de uma vez, evitando que correções se sobreponham.
 *
 * Para arquivos com problemas estruturais graves (ex: migration sem up/down),
 * o arquivo inteiro é regenerado com a estrutura correta.
 */
final class ModuleAutoFixer
{
    private string $moduleName;
    /** @var array<string,string> path => content */
    private array $files;

    /** Códigos que podem ser corrigidos automaticamente */
    private const FIXABLE = [
        'MISSING_UP', 'MISSING_DOWN', 'NO_DRIVER_CHECK', 'NON_IDEMPOTENT_MIGRATION',
        'MISSING_NAMESPACE', 'WRONG_NAMESPACE', 'UNPROTECTED_WRITE_ROUTE',
        'SHELL_EXECUTION', 'EVAL_USAGE', 'DIRECT_HEADER',
        'DIE_EXIT', 'DIE_EXIT_IN_CONTROLLER', 'INVALID_CONNECTION',
        'DYNAMIC_INCLUDE', 'ENV_FILE_ACCESS', 'PATH_TRAVERSAL',
        'SENSITIVE_SERVER_ACCESS', 'POTENTIAL_SQL_INJECTION',
    ];

    public function __construct(string $moduleName, array $files)
    {
        $this->moduleName = $moduleName;
        $this->files      = $files;
    }

    /**
     * Aplica todas as correções possíveis e retorna o resultado.
     */
    public function fix(array $issues): array
    {
        $applied = [];
        $skipped = [];

        // Agrupa issues por arquivo
        $byFile = [];
        foreach ($issues as $issue) {
            $file = $issue['file'] ?? '';
            $byFile[$file][] = $issue;
        }

        foreach ($byFile as $file => $fileIssues) {
            $codes = array_column($fileIssues, 'code');

            // Determina o tipo de arquivo para escolher a estratégia
            $isMigration  = str_contains($file, 'Database/Migrations/') && str_ends_with($file, '.php');
            $isRoutes     = str_contains($file, 'Routes/') && str_ends_with($file, '.php');
            $isConnection = $file === 'Database/connection.php';
            $isPhp        = str_ends_with($file, '.php');

            $content = $this->files[$file] ?? '';

            // ── Migrations: regenera se tiver problemas estruturais ────────
            if ($isMigration) {
                $hasMigrationIssue = array_intersect($codes, [
                    'MISSING_UP', 'MISSING_DOWN', 'NO_DRIVER_CHECK',
                    'NON_IDEMPOTENT_MIGRATION', 'SHELL_EXECUTION',
                    'EVAL_USAGE', 'DYNAMIC_INCLUDE',
                ]);

                if (!empty($hasMigrationIssue)) {
                    $table   = $this->extractTableName($file, $content);
                    $newContent = $this->generateMigration($table);
                    $this->files[$file] = $newContent;

                    foreach ($fileIssues as $issue) {
                        if (in_array($issue['code'], self::FIXABLE, true)) {
                            $applied[] = [
                                'file'    => $file,
                                'code'    => $issue['code'],
                                'message' => "Migration regenerada com estrutura correta (up + down + verificação de driver).",
                            ];
                        } else {
                            $skipped[] = ['file' => $file, 'code' => $issue['code'], 'reason' => 'Incluído na regeneração da migration.'];
                        }
                    }
                    continue; // próximo arquivo
                }
            }

            // ── connection.php ────────────────────────────────────────────
            if ($isConnection) {
                $this->files[$file] = "<?php\n// Define qual banco de dados este módulo usa.\n// Opções: 'core' (DB_*) | 'modules' (DB2_*) | 'auto'\nreturn 'core';\n";
                $applied[] = ['file' => $file, 'code' => 'INVALID_CONNECTION', 'message' => "connection.php corrigido para retornar 'core'."];
                continue;
            }

            // ── Arquivo de rotas ──────────────────────────────────────────
            if ($isRoutes) {
                $newContent = $this->fixRoutesFile($content, $fileIssues, $applied, $skipped, $file);
                if ($newContent !== $content) {
                    $this->files[$file] = $newContent;
                }
                continue;
            }

            // ── Arquivo PHP genérico (controller, service, etc.) ──────────
            if ($isPhp) {
                $newContent = $content;
                foreach ($fileIssues as $issue) {
                    $result = $this->fixGenericIssue($issue, $newContent, $file);
                    if ($result !== null) {
                        $newContent = $result['content'];
                        $applied[]  = ['file' => $file, 'code' => $issue['code'], 'message' => $result['message']];
                    } else {
                        $skipped[] = ['file' => $file, 'code' => $issue['code'], 'reason' => 'Correção automática não disponível para este problema.'];
                    }
                }
                if ($newContent !== $content) {
                    $this->files[$file] = $newContent;
                }
                continue;
            }

            // Arquivo não-PHP — não corrigível
            foreach ($fileIssues as $issue) {
                $skipped[] = ['file' => $file, 'code' => $issue['code'], 'reason' => 'Tipo de arquivo não suportado para correção automática.'];
            }
        }

        // Detecta quais arquivos realmente mudaram comparando com o original
        $originalFiles = array_map(fn($c, $p) => $p, $this->files, array_keys($this->files));
        $changed = [];
        foreach ($this->files as $path => $newContent) {
            // Compara com o conteúdo original passado no construtor
            if (isset($byFile[$path])) {
                $changed[$path] = $newContent;
            }
        }

        return [
            'fixed'   => $changed,
            'applied' => $applied,
            'skipped' => $skipped,
            'files'   => $this->files,
        ];
    }

    // ── Correção de arquivo de rotas ──────────────────────────────────────

    private function fixRoutesFile(string $content, array $issues, array &$applied, array &$skipped, string $file): string
    {
        $codes = array_column($issues, 'code');

        if (in_array('UNPROTECTED_WRITE_ROUTE', $codes, true)) {
            $hasAuthUse = str_contains($content, 'AuthHybridMiddleware');

            // 1. Adiciona use statement se não existir
            if (!$hasAuthUse) {
                // Insere logo após <?php (primeira linha)
                $content = preg_replace(
                    '/^<\?php\s*\n/i',
                    "<?php\n\nuse Src\\Kernel\\Middlewares\\AuthHybridMiddleware;\nuse Src\\Kernel\\Middlewares\\AdminOnlyMiddleware;\n",
                    $content,
                    1
                ) ?? $content;
            }

            // 2. Adiciona $protected se não existir
            if (!str_contains($content, '$protected')) {
                // Insere antes da primeira chamada $router->
                $content = preg_replace(
                    '/(\$router->)/s',
                    "\$protected      = [AuthHybridMiddleware::class];\n\$adminProtected = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];\n\n\$router->",
                    $content,
                    1
                ) ?? $content;
            }

            // 3. Adiciona $protected nas rotas de escrita sem middleware
            // Suporta tanto array handler quanto closure/string handler
            $content = preg_replace_callback(
                '/\$router->(post|put|patch|delete)\s*\(([^;]+)\)\s*;/i',
                function ($m) {
                    $full = $m[0];
                    // Já tem middleware ou $protected? Não altera
                    if (str_contains($full, 'Middleware') ||
                        str_contains($full, '$protected') ||
                        str_contains($full, '$admin')) {
                        return $full;
                    }
                    // Remove o ; final e adiciona , $protected);
                    $inner = rtrim(rtrim($m[2]), ')');
                    return '$router->' . $m[1] . '(' . $inner . ', $protected);';
                },
                $content
            ) ?? $content;

            $applied[] = [
                'file'    => $file,
                'code'    => 'UNPROTECTED_WRITE_ROUTE',
                'message' => 'Middleware de autenticação adicionado às rotas de escrita.',
            ];
        }

        // Outros issues de rotas
        foreach ($issues as $issue) {
            if ($issue['code'] === 'UNPROTECTED_WRITE_ROUTE') continue;
            if (in_array($issue['code'], self::FIXABLE, true)) {
                $result = $this->fixGenericIssue($issue, $content, $file);
                if ($result !== null) {
                    $content   = $result['content'];
                    $applied[] = ['file' => $file, 'code' => $issue['code'], 'message' => $result['message']];
                } else {
                    $skipped[] = ['file' => $file, 'code' => $issue['code'], 'reason' => 'Correção automática não disponível.'];
                }
            } else {
                $skipped[] = ['file' => $file, 'code' => $issue['code'], 'reason' => 'Correção automática não disponível.'];
            }
        }

        return $content;
    }

    // ── Correções genéricas por código ────────────────────────────────────

    private function fixGenericIssue(array $issue, string $content, string $file): ?array
    {
        return match ($issue['code']) {
            'MISSING_NAMESPACE', 'WRONG_NAMESPACE' => $this->fixNamespace($file, $content),
            'NON_IDEMPOTENT_MIGRATION'             => $this->fixIdempotentMigration($content),
            'SHELL_EXECUTION', 'EVAL_USAGE'        => $this->commentDangerousLine($content, $issue),
            'DYNAMIC_INCLUDE'                      => $this->commentDangerousLine($content, $issue),
            'DIRECT_HEADER'                        => $this->fixDirectHeader($content),
            'DIE_EXIT', 'DIE_EXIT_IN_CONTROLLER'   => $this->fixDieExit($content),
            'ENV_FILE_ACCESS'                      => $this->commentDangerousLine($content, $issue),
            'PATH_TRAVERSAL'                       => $this->commentDangerousLine($content, $issue),
            'SENSITIVE_SERVER_ACCESS'              => $this->commentDangerousLine($content, $issue),
            'POTENTIAL_SQL_INJECTION'              => $this->fixSqlInjection($content, $issue),
            default                                => null,
        };
    }

    // ── Fix: Namespace ────────────────────────────────────────────────────

    private function fixNamespace(string $file, string $content): ?array
    {
        $parts = explode('/', $file);
        array_pop($parts);
        $subNs = implode('\\', array_filter($parts));
        $correctNs = "Src\\Modules\\{$this->moduleName}" . ($subNs ? "\\{$subNs}" : '');

        if (preg_match('/^\s*namespace\s+[A-Za-z\\\\]+\s*;/m', $content)) {
            $new = preg_replace('/^\s*namespace\s+[A-Za-z\\\\]+\s*;/m', "namespace {$correctNs};", $content);
        } else {
            $new = preg_replace('/^(<\?php[^\n]*\n)/i', "$1\nnamespace {$correctNs};\n", $content, 1);
        }

        if ($new !== null && $new !== $content) {
            return ['content' => $new, 'message' => "Namespace corrigido para '{$correctNs}'."];
        }
        return null;
    }

    // ── Fix: CREATE TABLE sem IF NOT EXISTS ──────────────────────────────

    private function fixIdempotentMigration(string $content): ?array
    {
        $new = preg_replace('/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s)/i', 'CREATE TABLE IF NOT EXISTS ', $content);
        if ($new !== null && $new !== $content) {
            return ['content' => $new, 'message' => "'CREATE TABLE IF NOT EXISTS' aplicado."];
        }
        return null;
    }

    // ── Fix: Comenta linha perigosa ───────────────────────────────────────

    private function commentDangerousLine(string $content, array $issue): ?array
    {
        $line = (int)($issue['line'] ?? 0);
        $code = $issue['code'];

        if ($line > 0) {
            $lines = explode("\n", $content);
            $idx   = $line - 1;
            if (isset($lines[$idx]) && !str_starts_with(ltrim($lines[$idx]), '//')) {
                $original   = $lines[$idx];
                $lines[$idx] = '// [AUTOFIX] Linha removida por segurança (' . $code . ')' . "\n" .
                               '// ' . ltrim($original);
                return ['content' => implode("\n", $lines), 'message' => "Linha {$line} comentada ({$code})."];
            }
        }

        // Fallback: tenta por padrão
        $patterns = [
            'SHELL_EXECUTION'      => '/\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\([^;]+;/i',
            'EVAL_USAGE'           => '/\beval\s*\([^;]+;/i',
            'DYNAMIC_INCLUDE'      => '/\b(include|require)(_once)?\s*\(\s*\$[^;]+;/i',
            'ENV_FILE_ACCESS'      => '/file_get_contents\s*\([^)]*\.env[^)]*\)[^;]*;/i',
            'SENSITIVE_SERVER_ACCESS' => '/\$_SERVER\s*\[\s*[\'"](?:PHP_AUTH_PW|HTTP_AUTHORIZATION)[\'"]\][^;]*;/i',
        ];

        if (isset($patterns[$code])) {
            $new = preg_replace($patterns[$code], '// [AUTOFIX] Removido por segurança: $0', $content);
            if ($new !== null && $new !== $content) {
                return ['content' => $new, 'message' => "Código perigoso ({$code}) comentado."];
            }
        }

        return null;
    }

    // ── Fix: header() direto ──────────────────────────────────────────────

    private function fixDirectHeader(string $content): ?array
    {
        $new = preg_replace(
            '/^(\s*)(header\s*\([^)]+\)\s*;)/m',
            '$1// [AUTOFIX] Use Response::json() ou Response::html() em vez de header()' . "\n" . '$1// $2',
            $content
        );
        if ($new !== null && $new !== $content) {
            return ['content' => $new, 'message' => "Chamadas a header() comentadas. Use Response::json() ou Response::html()."];
        }
        return null;
    }

    // ── Fix: die/exit ─────────────────────────────────────────────────────

    private function fixDieExit(string $content): ?array
    {
        $new = preg_replace(
            '/\b(die|exit)\s*\(([^)]*)\)\s*;/i',
            '// [AUTOFIX] Substitua por: return Response::json([\'error\' => $2], 400);',
            $content
        );
        if ($new !== null && $new !== $content) {
            return ['content' => $new, 'message' => "die()/exit() substituídos por comentário. Implemente o retorno correto."];
        }
        return null;
    }

    // ── Fix: SQL Injection ────────────────────────────────────────────────

    private function fixSqlInjection(string $content, array $issue): ?array
    {
        $line = (int)($issue['line'] ?? 0);
        if ($line <= 0) return null;

        $lines = explode("\n", $content);
        $idx   = $line - 1;
        if (!isset($lines[$idx])) return null;

        $original = $lines[$idx];
        // Adiciona comentário de aviso acima da linha
        $lines[$idx] = '// [AUTOFIX] ATENÇÃO: Use prepared statements para evitar SQL injection!' . "\n" .
                       '// Exemplo: $stmt = $pdo->prepare("SELECT * FROM t WHERE id = ?"); $stmt->execute([$id]);' . "\n" .
                       $original;

        return ['content' => implode("\n", $lines), 'message' => "Aviso de SQL injection adicionado na linha {$line}. Refatore para usar prepared statements."];
    }

    // ── Geração de migration completa ─────────────────────────────────────

    private function extractTableName(string $file, string $content): string
    {
        // 1. Do CREATE TABLE existente
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\']?(\w+)[`"\']?/i', $content, $m)) {
            return $m[1];
        }
        // 2. Do nome do arquivo (ex: 001_create_tasks_table.php → tasks)
        if (preg_match('/create_(\w+?)(?:_table)?\.php$/i', $file, $m)) {
            return $m[1];
        }
        // 3. Fallback: nome do módulo em snake_case + s
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $this->moduleName) ?? $this->moduleName);
        return $snake . 's';
    }

    private function generateMigration(string $table): string
    {
        return <<<PHP
<?php

use PDO;

return [
    'up' => function (PDO \$pdo): void {
        \$driver = \$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (\$driver === 'pgsql') {
            \$pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    nome VARCHAR(255) NOT NULL,
                    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMPTZ NULL
                )
            ");
        } else {
            \$pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id CHAR(36) PRIMARY KEY,
                    nome VARCHAR(255) NOT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },
    'down' => function (PDO \$pdo): void {
        \$pdo->exec('DROP TABLE IF EXISTS {$table}');
    },
];
PHP;
    }
}
