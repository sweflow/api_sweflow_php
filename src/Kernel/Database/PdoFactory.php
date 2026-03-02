<?php

namespace Src\Database;

use PDO;
use RuntimeException;
use Throwable;

class PdoFactory
{
    public static function fromEnv(): PDO
    {
        $driver = strtolower($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql');
        if ($driver === 'postgresql') {
            $driver = 'pgsql';
        }
        $host = $_ENV['DB_HOST'] ?? '';
        $name = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $user = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
        $pass = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
        $port = $_ENV['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306');

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('Configuração do banco incompleta.');
        }

        $dsn = $driver === 'pgsql'
            ? "pgsql:host={$host};port={$port};dbname={$name}"
            : "mysql:host={$host};port={$port};dbname={$name}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ];

        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível conectar ao banco: ' . $e->getMessage(), 0, $e);
        }
    }
}
