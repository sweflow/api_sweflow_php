<?php

declare(strict_types=1);

namespace Src\Kernel\Nucleo;

/**
 * ModuleGuard — Camada de isolamento e proteção para módulos de terceiros.
 *
 * Garante que nenhum módulo possa:
 *   1. Sobrescrever rotas do kernel ou de módulos protegidos
 *   2. Registrar rotas em prefixos reservados do sistema
 *   3. Declarar namespaces proibidos (Src\Kernel\*)
 *   4. Usar nomes de módulo reservados
 *   5. Executar código de rota que lance exceções sem captura (isolamento por try/catch)
 *
 * O kernel chama este guard ANTES de registrar qualquer rota de módulo.
 */
final class ModuleGuard
{
    /** Prefixos de URI que pertencem exclusivamente ao kernel — módulos não podem usá-los */
    private const RESERVED_URI_PREFIXES = [
        '/api/auth/',
        '/api/login',
        '/api/logout',
        '/api/registrar',
        '/api/criar/usuario',
        '/api/usuario',
        '/api/usuarios',
        '/api/perfil',
        '/api/system/',
        '/api/modules/',
        '/api/capabilities',
        '/api/ide/',
        '/dashboard',
        '/api/db-status',
    ];

    /** Nomes de módulo reservados pelo kernel */
    private const RESERVED_MODULE_NAMES = [
        'Auth', 'Usuario', 'Kernel', 'System', 'Core',
        'IdeModuleBuilder', 'Documentacao',
    ];

    /** Namespaces que módulos externos não podem declarar */
    private const FORBIDDEN_NAMESPACES = [
        'Src\\Kernel\\',
        'Src\\Modules\\Auth\\',
        'Src\\Modules\\Usuario\\',
        'Src\\Modules\\IdeModuleBuilder\\',
    ];

    /**
     * Valida se um módulo pode ser registrado.
     * Lança \RuntimeException se o módulo violar alguma regra.
     */
    public static function assertModuleAllowed(string $moduleName, string $modulePath): void
    {
        // 1. Nome reservado
        if (in_array($moduleName, self::RESERVED_MODULE_NAMES, true)) {
            throw new \RuntimeException(
                "Módulo '{$moduleName}' usa um nome reservado pelo sistema e não pode ser registrado externamente."
            );
        }

        // 2. Nome com caracteres inválidos (apenas PascalCase alfanumérico)
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $moduleName)) {
            throw new \RuntimeException(
                "Nome de módulo inválido: '{$moduleName}'. Use apenas letras e números (PascalCase, máx 64 chars)."
            );
        }

        // 3. Path traversal no caminho do módulo
        $realPath = realpath($modulePath);
        if ($realPath === false) {
            throw new \RuntimeException(
                "Caminho do módulo '{$moduleName}' não existe ou não é acessível: {$modulePath}"
            );
        }

        // 4. Verifica se o módulo tenta declarar namespaces proibidos nos seus arquivos PHP
        // (verificação leve via grep de string — não executa o código)
        self::assertNoForbiddenNamespaces($moduleName, $realPath);
    }

    /**
     * Valida se uma URI que um módulo tenta registrar é permitida.
     * Retorna false se a URI for reservada (não lança exceção — apenas bloqueia silenciosamente
     * para não derrubar o boot do sistema por causa de um módulo mal-comportado).
     */
    public static function isUriAllowed(string $uri, string $moduleName): bool
    {
        $uriLower = strtolower($uri);
        foreach (self::RESERVED_URI_PREFIXES as $prefix) {
            if (str_starts_with($uriLower, strtolower($prefix))) {
                error_log(
                    "[ModuleGuard] BLOQUEADO: módulo '{$moduleName}' tentou registrar rota reservada: {$uri}"
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Executa o carregamento do arquivo de rotas de um módulo dentro de um try/catch
     * para que qualquer exceção/erro no código do módulo não derrube o sistema.
     *
     * @param callable $loader  Closure que faz o require do arquivo de rotas
     * @param string   $moduleName  Nome do módulo (para log)
     * @return bool  true se carregou sem erros, false se houve exceção
     */
    public static function safeLoadRoutes(callable $loader, string $moduleName): bool
    {
        try {
            $loader();
            return true;
        } catch (\Throwable $e) {
            error_log(
                "[ModuleGuard] ERRO ao carregar rotas do módulo '{$moduleName}': " .
                get_class($e) . ': ' . $e->getMessage() .
                ' em ' . $e->getFile() . ':' . $e->getLine()
            );
            return false;
        }
    }

    /**
     * Executa o boot de um módulo de forma segura.
     * Erros no boot de um módulo não afetam o sistema.
     */
    public static function safeBoot(callable $bootFn, string $moduleName): bool
    {
        try {
            $bootFn();
            return true;
        } catch (\Throwable $e) {
            error_log(
                "[ModuleGuard] ERRO no boot do módulo '{$moduleName}': " .
                get_class($e) . ': ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Verifica se os arquivos PHP do módulo tentam declarar namespaces proibidos.
     * Usa leitura de string (não executa o código) — verificação estática leve.
     */
    private static function assertNoForbiddenNamespaces(string $moduleName, string $realPath): void
    {
        // Verifica recursivamente todos os arquivos PHP do módulo
        $phpFiles = self::findPhpFilesRecursive($realPath);

        foreach ($phpFiles as $file) {
            $content = @file_get_contents($file);
            if ($content === false) continue;

            if (!preg_match('/^\s*namespace\s+([A-Za-z\\\\]+)\s*;/m', $content, $m)) {
                continue;
            }

            $declaredNs = $m[1] . '\\';
            foreach (self::FORBIDDEN_NAMESPACES as $forbidden) {
                if (str_starts_with($declaredNs, $forbidden)) {
                    throw new \RuntimeException(
                        "Módulo '{$moduleName}' declara namespace proibido '{$m[1]}' " .
                        "no arquivo " . basename($file) . ". " .
                        "Módulos não podem usar namespaces do kernel ou de módulos protegidos."
                    );
                }
            }
        }
    }

    private static function findPhpFilesRecursive(string $dir, int $depth = 0): array
    {
        if ($depth > 6) return []; // limite de profundidade para evitar loops
        $files = [];
        foreach (glob($dir . '/*.php') ?: [] as $f) {
            $files[] = $f;
        }
        foreach (glob($dir . '/*/') ?: [] as $subDir) {
            foreach (self::findPhpFilesRecursive(rtrim($subDir, '/'), $depth + 1) as $f) {
                $files[] = $f;
            }
        }
        return $files;
    }

    /**
     * Retorna os prefixos reservados (para uso em documentação/dashboard).
     */
    public static function reservedPrefixes(): array
    {
        return self::RESERVED_URI_PREFIXES;
    }

    /**
     * Retorna os nomes reservados (para uso em validação da IDE).
     */
    public static function reservedNames(): array
    {
        return self::RESERVED_MODULE_NAMES;
    }
}
