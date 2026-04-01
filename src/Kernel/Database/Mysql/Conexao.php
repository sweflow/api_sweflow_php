<?php


namespace Src\Database\Mysql;

use Src\Database\Exceptions\DatabaseException;
use Src\Database\Exceptions\DatabaseConnectionException;
use Src\Database\Exceptions\DatabaseConfigException;
use Src\Database\Exceptions\DatabaseDriverException;
use Src\Database\Exceptions\DatabaseIntegrityException;
use Src\Database\Exceptions\DatabasePermissionException;
use Src\Database\Exceptions\DatabaseQueryException;
use Src\Database\Exceptions\DatabaseTimeoutException;
use Src\Database\Exceptions\DatabaseTransactionException;

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
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? 3306;
            $database = $_ENV['DB_NOME'] ?? '';
            $username = $_ENV['DB_USUARIO'] ?? '';
            $password = $_ENV['DB_SENHA'] ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
            $collation = $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci';

            // DSN (Data Source Name) para MySQL
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $port,
                $database,
                $charset
            );

            // Opções de conexão para segurança e performance
    private static function tratarErroCustomizada(PDOException $e): never
    {
        $mensagem = self::getMensagemErro($e);
        $codigo = (int)$e->getCode();

        // Mapeamento de códigos de erro MySQL para Exceptions customizadas
        self::lançarExceptionPorCodigo($codigo, $mensagem, $e);
    }

    private static function getMensagemErro(PDOException $e): string
    {
        $debug = getenv('APP_DEBUG') === 'true';
        return $debug 
            ? $e->getMessage()
            : 'Erro ao conectar ao banco de dados. Contate o administrador.';
    }

    private static function lançarExceptionPorCodigo(int $codigo, string $mensagem, PDOException $e): never
    {
        if (in_array($codigo, [1045, 1044], true)) {
            throw new DatabasePermissionException($mensagem, $codigo, $e);
        }

        if (in_array($codigo, [1049, 1046], true)) {
            throw new DatabaseConfigException($mensagem, $codigo, $e);
        }

        if (in_array($codigo, [2002, 2003, 2006], true)) {
            throw new DatabaseConnectionException($mensagem, $codigo, $e);
        }

        if (in_array($codigo, [1062, 1451, 1452], true)) {
            throw new DatabaseIntegrityException($mensagem, $codigo, $e);
        }

        throw new PDOException($mensagem, $codigo, $e);
    }
            case 1045: // Access denied
            case 1044: // Access denied for user to database
                throw new DatabasePermissionException($mensagem, $codigo, $e);
            case 1049: // Unknown database
            case 1046: // No database selected
                throw new DatabaseConfigException($mensagem, $codigo, $e);
            case 2002: // Connection refused
            case 2003: // Can't connect to MySQL server
            case 2006: // MySQL server has gone away
                throw new DatabaseConnectionException($mensagem, $codigo, $e);
            case 1062: // Duplicate entry (integrity)
            case 1451: // Cannot delete or update a parent row: a foreign key constraint fails
            case 1452: // Cannot add or update a child row: a foreign key constraint fails
                throw new DatabaseIntegrityException($mensagem, $codigo, $e);
            case 1205: // Lock wait timeout exceeded
            case 1213: // Deadlock found
                throw new DatabaseTimeoutException($mensagem, $codigo, $e);
            case 1064: // SQL syntax error
            case 1146: // Table doesn't exist
            case 1054: // Unknown column
            case 1364: // Field doesn't have a default value
                throw new DatabaseQueryException($mensagem, $codigo, $e);
            case 1194: // Table is crashed
            case 1195: // Table is crashed and last repair failed
                throw new DatabaseDriverException($mensagem, $codigo, $e);
            case 1200: // Transaction rollback
            case 1201: // Transaction commit
                throw new DatabaseTransactionException($mensagem, $codigo, $e);
            default:
                throw new DatabaseException($mensagem, $codigo, $e);
        }
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
            self::tratarErroCustomizada($e); // Lança exceção customizada
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
