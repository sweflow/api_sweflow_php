<?php

namespace Src\Kernel\Database\Mysql;

use Src\Kernel\Database\Exceptions\DatabaseException;
use Src\Kernel\Database\Exceptions\DatabaseConnectionException;
use Src\Kernel\Database\Exceptions\DatabaseConfigException;
use Src\Kernel\Database\Exceptions\DatabaseDriverException;
use Src\Kernel\Database\Exceptions\DatabaseIntegrityException;
use Src\Kernel\Database\Exceptions\DatabasePermissionException;
use Src\Kernel\Database\Exceptions\DatabaseQueryException;
use Src\Kernel\Database\Exceptions\DatabaseTimeoutException;
use Src\Kernel\Database\Exceptions\DatabaseTransactionException;

use PDO;
use PDOException;

/**
 * Classe de Conexão com MySQL
 */
class Conexao
{
    /**
     * Instância única da conexão (Singleton)
     *
     * @var PDO|null
     */
    private static ?PDO $pdo = null;

    /**
     * Previne instanciação direta
     */
    private function __construct()
    {
        // Classe não deve ser instanciada
    }

    /**
     * Previne clonagem da instância
     */
    private function __clone()
    {
        // Classe não deve ser clonada
    }

    /**
     * Obtém a instância única da conexão (Padrão Singleton)
     *
     * @return PDO
     * @throws PDOException
     */
    public static function conectar(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::criarConexao();
        }
        return self::$pdo;
    }

    /**
     * Cria a conexão com o banco de dados
     *
     * @return PDO
     * @throws PDOException
     */
    private static function criarConexao(): PDO
    {
        try {
            // Cast to string and restrict to safe characters to prevent DSN injection
            $host     = preg_replace('/[^a-zA-Z0-9.\-_]/', '', (string) ($_ENV['DB_HOST'] ?? 'localhost'));
            $port     = (int) ($_ENV['DB_PORT'] ?? 3306);
            $database = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_ENV['DB_NOME'] ?? ''));
            $username = (string) ($_ENV['DB_USUARIO'] ?? '');
            $password = (string) ($_ENV['DB_SENHA'] ?? '');
            $charset  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4'));

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw self::resolverExcecao($e);
        }
    }

    private static function resolverExcecao(PDOException $e): DatabaseException
    {
        $mensagem = self::getMensagemErro($e);
        $codigo = (int)$e->getCode();

        if (in_array($codigo, [1045, 1044], true)) {
            return new DatabasePermissionException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [1049, 1046], true)) {
            return new DatabaseConfigException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [2002, 2003, 2006], true)) {
            return new DatabaseConnectionException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [1062, 1451, 1452], true)) {
            return new DatabaseIntegrityException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [1205, 1213], true)) {
            return new DatabaseTimeoutException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [1064, 1146, 1054, 1364], true)) {
            return new DatabaseQueryException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [1194, 1195], true)) {
            return new DatabaseDriverException($mensagem, $codigo, $e);
        }
        if (in_array($codigo, [1200, 1201], true)) {
            return new DatabaseTransactionException($mensagem, $codigo, $e);
        }

        return new DatabaseException($mensagem, $codigo, $e);
    }

    private static function getMensagemErro(PDOException $e): string
    {
        $debug = getenv('APP_DEBUG') === 'true';
        return $debug
            ? $e->getMessage()
            : 'Erro ao conectar ao banco de dados. Contate o administrador.';
    }



    /**
     * Obtém uma conexão com o banco de dados
     * Alias para o método conectar()
     *
     * @return PDO
     */
    public static function getConexao(): PDO
    {
        return self::conectar();
    }

    /**
     * Fecha a conexão (útil em testes ou encerramento)
     *
     * @return void
     */
    public static function desconectar(): void
    {
        self::$pdo = null;
    }

    /**
     * Verifica se está conectado
     *
     * @return bool
     */
    public static function estaConectado(): bool
    {
        return self::$pdo instanceof PDO;
    }

    /**
     * Executa uma query com prepared statements
     *
     * @param string $sql
     * @param array $parametros
     * @return \PDOStatement
     * @throws PDOException
     */
    public static function executar(string $sql, array $parametros = []): \PDOStatement
    {
        try {
            $stmt = self::conectar()->prepare($sql);
            $stmt->execute($parametros);
            return $stmt;
        } catch (PDOException $e) {
            throw self::resolverExcecao($e);
        }
    }

    /**
     * Busca um registro
     *
     * @param string $sql
     * @param array $parametros
     * @return array|false
     * @throws PDOException
     */
    public static function buscar(string $sql, array $parametros = []): array|false
    {
        $stmt = self::executar($sql, $parametros);
        return $stmt->fetch();
    }

    /**
     * Busca todos os registros
     *
     * @param string $sql
     * @param array $parametros
     * @return array
     * @throws PDOException
     */
    public static function buscarTodos(string $sql, array $parametros = []): array
    {
        $stmt = self::executar($sql, $parametros);
        return $stmt->fetchAll();
    }
}
