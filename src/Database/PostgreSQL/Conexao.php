<?php

namespace src\Database\PostgreSQL;
use src\Database\Exceptions\DatabaseException;
use src\Database\Exceptions\DatabaseConnectionException;
use src\Database\Exceptions\DatabaseQueryException;
use src\Database\Exceptions\DatabaseTransactionException;
use src\Database\Exceptions\DatabaseIntegrityException;

use PDO;
use PDOException;

/**
 * Classe de Conexão com PostgreSQL
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
     * @throws DatabaseException
     */
    public static function conectar(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::criarConexao();
        }

        return self::$pdo;
    }

    /**
     * Cria a conexão com o banco de dados PostgreSQL
     *
     * @return PDO
     * @throws PDOException
     */
    private static function criarConexao(): PDO
    {
        try {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? 5432;
            $database = $_ENV['DB_NOME'] ?? '';
            $username = $_ENV['DB_USUARIO'] ?? '';
            $password = $_ENV['DB_SENHA'] ?? '';

            // DSN (Data Source Name) para PostgreSQL
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $host,
                $port,
                $database
            );

            // Opções de conexão para segurança e performance
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, // Conexão não persistente por segurança
            ];

            // Criação da instância PDO
            $pdo = new PDO($dsn, $username, $password, $options);

            // Configurar aplicação de caracteres
            $pdo->exec("SET client_encoding TO 'UTF8'");

            return $pdo;

        } catch (PDOException $e) {
            self::tratarErro($e);
        }
    }

    /**
     * Trata erros de conexão
     * Este método sempre lança uma exceção e nunca retorna normalmente
     *
     * @param PDOException $e
     * @throws PDOException
     * @return never
     */
    private static function tratarErro(PDOException $e): never
    {
        $debug = getenv('APP_DEBUG') === 'true';

        $sqlState = $e->getCode();
        $mensagem = $debug
            ? "Erro ao conectar ao banco de dados PostgreSQL: " . $e->getMessage()
            : "Erro ao conectar ao banco de dados. Contate o administrador.";
        $codigo = $debug ? (int)$e->getCode() : 1;

        // SQLSTATE: https://www.postgresql.org/docs/current/errcodes-appendix.html
        // 08001, 08006, 08004, 08003: erros de conexão
        if (in_array($sqlState, ['08001', '08006', '08004', '08003'])) {
            throw new DatabaseConnectionException($mensagem, $codigo, $e);
        }
        // 23000: violação de integridade (chave primária, estrangeira, etc)
        if (str_starts_with($sqlState, '23')) {
            throw new DatabaseIntegrityException($mensagem, $codigo, $e);
        }
        // 40001, 40P01: erro de transação (deadlock, serialization failure)
        if (in_array($sqlState, ['40001', '40P01'])) {
            throw new DatabaseTransactionException($mensagem, $codigo, $e);
        }
        // 42000: erro de sintaxe SQL ou permissão
        if (str_starts_with($sqlState, '42')) {
            throw new DatabaseQueryException($mensagem, $codigo, $e);
        }
        // Fallback: erro genérico
        throw new DatabaseException($mensagem, $codigo, $e);
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
     * @throws DatabaseException
     */
    public static function executar(string $sql, array $parametros = []): \PDOStatement
    {
        try {
            $stmt = self::conectar()->prepare($sql);
            $stmt->execute($parametros);
            return $stmt;
        } catch (PDOException $e) {
            self::tratarErro($e); // Lança exceção, nunca retorna
        }
    }

    /**
     * Busca um registro
     *
     * @param string $sql
     * @param array $parametros
     * @return array|false
     * @throws DatabaseException
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
     * @throws DatabaseException
     */
    public static function buscarTodos(string $sql, array $parametros = []): array
    {
        $stmt = self::executar($sql, $parametros);
        return $stmt->fetchAll();
    }

    /**
     * Executa uma inserção e retorna o ID gerado
     * Específico para PostgreSQL que usa SERIAL ou BIGSERIAL
     *
     * @param string $sql
     * @param array $parametros
     * @param string $sequencia Nome da sequência (ex: 'usuarios_id_seq')
     * @return string|false ID gerado ou false em caso de falha
     * @throws DatabaseException
     */
    public static function inserirComId(string $sql, array $parametros = [], string $sequencia = ''): string|false
    {
        try {
            $stmt = self::executar($sql, $parametros);
            
            if ($sequencia) {
                return self::conectar()->lastInsertId($sequencia);
            }
            
            return $stmt->rowCount() > 0 ? self::conectar()->lastInsertId() : false;
        } catch (PDOException $e) {
            self::tratarErro($e);
        }
    }

    /**
     * Executa uma transação
     *
     * @param callable $callback Função que contém as operações da transação
     * @return mixed Retorna o resultado da callback
     * @throws DatabaseException
     */
    public static function transacao(callable $callback): mixed
    {
        $pdo = self::conectar();
        try {
            $pdo->beginTransaction();
            $resultado = $callback($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
