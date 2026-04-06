<?php

namespace Src\Kernel\Database;

use PDO;

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
     * Lê o Database/connection.php do módulo e retorna DB ou DB2 conforme definido.
     */
    public static function forModule(string $moduleName): PDO
    {
        if (isset(self::$cache[$moduleName])) {
            return self::$cache[$moduleName];
        }

        $conn   = self::readConnectionFile($moduleName);
        $prefix = ($conn === 'modules' && PdoFactory::hasSecondaryConnection()) ? 'DB2' : 'DB';

        return self::$cache[$moduleName] = PdoFactory::fromEnv($prefix);
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
