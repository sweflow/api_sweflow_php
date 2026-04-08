<?php

namespace Src\Kernel\Database;

use PDO;
use RuntimeException;
use Throwable;

class PdoFactory
{
    /**
     * Cria uma conexão PDO a partir das variáveis de ambiente.
     * Se o banco não existir, tenta criá-lo automaticamente.
     *
     * @param string $prefix Prefixo das variáveis — 'DB' para conexão principal (core),
     *                       'DB2' para conexão secundária (módulos externos).
     */
    public static function fromEnv(string $prefix = 'DB'): PDO
    {
        $p = strtoupper($prefix);

        $driver = strtolower(self::env("{$p}_CONEXAO", self::env("{$p}_CONNECTION", self::env('DB_CONEXAO', self::env('DB_CONNECTION', 'mysql')))));
        if ($driver === '') {
            $driver = 'mysql';
        }
        if ($driver === 'postgresql') {
            $driver = 'pgsql';
        }

        $host = self::env("{$p}_HOST",    self::env('DB_HOST',    ''));
        $name = self::env("{$p}_NOME",    self::env("{$p}_DATABASE", self::env('DB_NOME', self::env('DB_DATABASE', ''))));
        $user = self::env("{$p}_USUARIO", self::env("{$p}_USERNAME", self::env('DB_USUARIO', self::env('DB_USERNAME', ''))));
        $pass = self::env("{$p}_SENHA",   self::env("{$p}_PASSWORD", self::env('DB_SENHA', self::env('DB_PASSWORD', ''))));
        $port = self::env("{$p}_PORT",    $driver === 'pgsql' ? '5432' : '3306');

        $charsetRaw      = self::env("{$p}_CHARSET", 'utf8mb4');
        $allowedCharsets = ['utf8mb4', 'utf8', 'latin1', 'ascii', 'binary'];
        $charset         = in_array(strtolower($charsetRaw), $allowedCharsets, true)
            ? strtolower($charsetRaw)
            : 'utf8mb4';

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException("Configuração do banco incompleta para prefixo [{$p}].");
        }

        $connectTimeout = 5; // segundos — evita travar o processo inteiro

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => $connectTimeout,
        ];

        // Tenta conectar; se o banco não existir, cria automaticamente
        try {
            // connect_timeout no DSN é o único jeito confiável de limitar
            // o tempo de handshake TCP no MySQL/PDO (ATTR_TIMEOUT não cobre isso)
            $dsn = $driver === 'pgsql'
                ? "pgsql:host={$host};port={$port};dbname={$name};connect_timeout={$connectTimeout}"
                : "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            if ($driver !== 'pgsql') {
                $waitTimeout = (int) self::env('DB_WAIT_TIMEOUT', '3600');
                $waitTimeout = $waitTimeout > 0 ? $waitTimeout : 3600;
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION wait_timeout={$waitTimeout}";
            }

            return new PDO($dsn, $user, $pass, $options);
        } catch (Throwable $e) {
            // Tenta criar o banco se o erro for "banco não existe"
            if (self::isDatabaseNotFoundError($e)) {
                self::createDatabase($driver, $host, $port, $name, $user, $pass, $charset, $options);
                // Reconecta após criar
                $dsn = $driver === 'pgsql'
                    ? "pgsql:host={$host};port={$port};dbname={$name};connect_timeout={$connectTimeout}"
                    : "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
                try {
                    return new PDO($dsn, $user, $pass, $options);
                } catch (Throwable $e2) {
                    throw new RuntimeException("Não foi possível conectar ao banco [{$p}] após criação: " . $e2->getMessage(), 0, $e2);
                }
            }
            throw new RuntimeException("Não foi possível conectar ao banco [{$p}]: " . $e->getMessage(), 0, $e);
        }
    }

    private static function isDatabaseNotFoundError(Throwable $e): bool
    {
        $msg  = $e->getMessage();
        $code = $e->getCode();
        return str_contains($msg, 'Unknown database')                                    // MySQL: banco não existe
            || ($code === 1049)                                                          // MySQL SQLSTATE 1049
            || str_contains($msg, 'SQLSTATE[HY000] [1049]')                             // MySQL via PDO
            || (str_contains($msg, 'database') && str_contains($msg, 'does not exist')) // PostgreSQL
            || (str_contains($msg, 'SQLSTATE[08006]') && str_contains($msg, 'does not exist')); // pgsql via PDO
    }

    private static function createDatabase(
        string $driver, string $host, string $port,
        string $name, string $user, string $pass,
        string $charset, array $options
    ): void {
        $connectTimeout = $options[PDO::ATTR_TIMEOUT] ?? 5;
        try {
            if ($driver === 'pgsql') {
                $adminDsn = "pgsql:host={$host};port={$port};dbname=postgres;connect_timeout={$connectTimeout}";
                $admin    = new PDO($adminDsn, $user, $pass, $options);
                // Usa formato seguro — pg_catalog.quote_ident não disponível via PDO, usa aspas duplas
                $safeName = str_replace('"', '', $name); // sanitiza
                $admin->exec("CREATE DATABASE \"{$safeName}\"");
                error_log("[PdoFactory] Banco PostgreSQL '{$name}' criado automaticamente.");
            } else {
                // MySQL: conecta sem banco para criar
                $adminDsn = "mysql:host={$host};port={$port};charset={$charset}";
                $admin    = new PDO($adminDsn, $user, $pass, $options);
                $safeName = str_replace('`', '', $name); // sanitiza
                $admin->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
                error_log("[PdoFactory] Banco MySQL '{$name}' criado automaticamente.");
            }
        } catch (Throwable $e) {
            // Mensagem clara quando falta permissão
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied') || str_contains($msg, 'permission denied')) {
                throw new RuntimeException(
                    "Sem permissão para criar o banco '{$name}'.\n" .
                    "  Execute manualmente:\n" .
                    ($driver === 'pgsql'
                        ? "    CREATE DATABASE \"{$name}\";"
                        : "    CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset};\n" .
                          "    GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'%'; FLUSH PRIVILEGES;"),
                    0, $e
                );
            }
            throw new RuntimeException("Não foi possível criar o banco '{$name}': " . $msg, 0, $e);
        }
    }

    /**
     * Retorna true se as variáveis DB2_* estão definidas no ambiente,
     * indicando que uma segunda conexão foi configurada.
     */
    public static function hasSecondaryConnection(): bool
    {
        return self::env('DB2_NOME', self::env('DB2_DATABASE', '')) !== '';
    }

    /** Lê uma variável de ambiente de $_ENV ou getenv(), com fallback. */
    private static function env(string $key, string $default = ''): string
    {
        $val = $_ENV[$key] ?? null;
        if ($val !== null && $val !== '') {
            return (string) $val;
        }
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }
        return $default;
    }
}
