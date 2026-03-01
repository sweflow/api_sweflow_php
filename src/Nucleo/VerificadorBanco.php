<?php
namespace Src\Nucleo;

class VerificadorBanco
{
    public function verificar(): array
    {
        $status = [
            'conectado' => false,
            'erro' => ''
        ];
        $tipo = $_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql';
        if ($tipo === 'postgresql') $tipo = 'pgsql';
        $host = $_ENV['DB_HOST'] ?? '';
        $banco = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $usuario = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
        $senha = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
        $porta = $_ENV['DB_PORT'] ?? ($tipo === 'pgsql' ? '5432' : '3306');
        $podeConectar = $host && $banco && $usuario;
        if ($podeConectar) {
            try {
                set_error_handler(function(){});
                $opcoes = [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 2
                ];
                if ($tipo === 'pgsql') {
                    $dsn = "pgsql:host=$host;port=$porta;dbname=$banco";
                } else {
                    $dsn = "mysql:host=$host;port=$porta;dbname=$banco";
                }
                $pdo = @new \PDO($dsn, $usuario, $senha, $opcoes);
                @$pdo->query($tipo === 'pgsql' ? 'SELECT 1' : 'SELECT 1');
                $status['conectado'] = true;
                restore_error_handler();
            } catch (\Throwable $e) {
                restore_error_handler();
                $status['erro'] = $e->getMessage();
            }
        } else {
            $status['erro'] = 'Configuração do banco incompleta.';
        }
        return $status;
    }
}
