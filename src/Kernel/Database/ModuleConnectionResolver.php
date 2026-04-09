<?php

namespace Src\Kernel\Database;

use PDO;
use RuntimeException;

/**
 * Resolve qual conexão PDO usar para um módulo baseado no seu connection.php.
 *
 * Uso:
 *   $pdo = ModuleConnectionResolver::forModule('Usuario');
 *   $pdo = ModuleConnectionResolver::forModule('Auth');
 */
class ModuleConnectionResolver
{
    /** Cache de PDO por módulo para evitar múltiplas conexões */
    private static array $cache = [];

    /**
     * Retorna o PDO correto para o módulo informado.
     *
     * Lança exceção descritiva quando:
     *   - Módulo declara 'modules' mas DB2_* não está configurado
     *   - Módulo declara 'core' mas DB_* não está configurado
     *   - Conexão falha por qualquer motivo
     */
    public static function forModule(string $moduleName): PDO
    {
        if (isset(self::$cache[$moduleName])) {
            return self::$cache[$moduleName];
        }

        $conn = self::readConnectionFile($moduleName);

        // Módulo quer DB2 mas não está configurado
        if ($conn === 'modules' && !PdoFactory::hasSecondaryConnection()) {
            throw new RuntimeException(
                "O módulo '{$moduleName}' está configurado para usar a conexão secundária (DB2_*), " .
                "mas nenhuma configuração de DB2 foi encontrada no .env.\n" .
                "Soluções:\n" .
                "  1. Configure DB2_NOME, DB2_HOST, DB2_USUARIO, DB2_SENHA no .env\n" .
                "  2. Ou altere src/Modules/{$moduleName}/Database/connection.php para retornar 'core'",
                503
            );
        }

        // Módulo quer DB core — verifica se está configurado
        if (in_array($conn, ['core', 'auto'], true)) {
            $dbNome = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_NOME') ?: getenv('DB_DATABASE') ?: '';
            $dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '';
            if ($dbNome === '' || $dbHost === '') {
                throw new RuntimeException(
                    "O módulo '{$moduleName}' usa a conexão principal (DB_*), " .
                    "mas DB_HOST ou DB_NOME não estão configurados no .env.",
                    503
                );
            }
        }

        $prefix = ($conn === 'modules' && PdoFactory::hasSecondaryConnection()) ? 'DB2' : 'DB';

        try {
            return self::$cache[$moduleName] = PdoFactory::fromEnv($prefix);
        } catch (\Throwable $e) {
            $connLabel = $prefix === 'DB2' ? 'secundária (DB2_*)' : 'principal (DB_*)';
            throw new RuntimeException(
                "O módulo '{$moduleName}' não conseguiu conectar à conexão {$connLabel}: " .
                $e->getMessage(),
                503,
                $e
            );
        }
    }

    /**
     * Lê o connection.php do módulo e retorna 'core', 'modules' ou 'auto'.
     */
    public static function readConnectionFile(string $moduleName): string
    {
        $candidates = [
            dirname(__DIR__, 3) . '/src/Modules/' . $moduleName . '/Database/connection.php',
            dirname(__DIR__, 3) . '/src/Modules/' . $moduleName . '/src/Database/connection.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                $value = include $file;
                if (is_string($value) && in_array($value, ['core', 'modules', 'auto'], true)) {
                    return $value;
                }
            }
        }

        return 'core';
    }

    /** Limpa o cache (útil em testes) */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
