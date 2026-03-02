<?php

namespace Src\Middlewares;

// Garante acesso ao autoload e JWT
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Src\Modules\Usuario\Repositories\UsuarioRepository;

class AuthCookieMiddleware
{
    public static function handle(): void
    {
        $token = $_COOKIE['auth_token'] ?? '';
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            self::responder(401, 'Não autenticado: cookie ausente.');
        }

        $payload = self::decodificarJwt($token);
        self::validarClaimsUsuario($payload);

        $usuario = self::repositorio()->buscarPorUuid($payload->sub);
        if (!$usuario) {
            self::responder(401, 'Usuário não encontrado.');
        }

        // Expiração já é validada pelo decode; guarda para uso no controller
        $GLOBALS['__auth_user'] = $usuario;
        $GLOBALS['__auth_payload'] = $payload;
    }

    private static function decodificarJwt(string $token): object
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        if ($secret === '') {
            self::responder(500, 'JWT_SECRET não configurado.');
            throw new DomainException('JWT_SECRET não configurado.');
        }

        try {
            return JWT::decode($token, new Key($secret, 'HS256'));
        } catch (DomainException $e) {
            self::responder(401, 'Token inválido: ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::responder(401, 'Token inválido ou expirado.');
        }

        // Salvaguarda para análise estática: todas as rotas retornam ou lançam.
        throw new DomainException('Token inválido ou expirado.');
    }

    private static function validarClaimsUsuario(object $payload): void
    {
        if (!isset($payload->sub)) {
            self::responder(401, 'Token sem identificador de usuário.');
        }

        // Garante que é token de usuário
        if (!isset($payload->tipo) || $payload->tipo !== 'user') {
            self::responder(401, 'Token não é de usuário.');
        }

        $iss = $_ENV['JWT_ISSUER'] ?? getenv('JWT_ISSUER') ?? null;
        if ($iss && (!isset($payload->iss) || $payload->iss !== $iss)) {
            self::responder(401, 'Emissor do token inválido.');
        }

        $aud = $_ENV['JWT_AUDIENCE'] ?? getenv('JWT_AUDIENCE') ?? null;
        if ($aud && (!isset($payload->aud) || $payload->aud !== $aud)) {
            self::responder(401, 'Audiência do token inválida.');
        }
    }

    private static function repositorio(): UsuarioRepository
    {
        static $repo = null;
        if ($repo !== null) {
            return $repo;
        }

        $dbType = $_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql';
        if ($dbType === 'postgresql') {
            $dbType = 'pgsql';
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $nome = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $usuario = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
        $senha = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
        $porta = $_ENV['DB_PORT'] ?? ($dbType === 'pgsql' ? '5432' : '3306');

        $dsn = $dbType === 'pgsql'
            ? "pgsql:host={$host};port={$porta};dbname={$nome}"
            : "mysql:host={$host};port={$porta};dbname={$nome}";

        $pdo = new PDO($dsn, $usuario, $senha, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);

        $repo = new UsuarioRepository($pdo);
        return $repo;
    }

    private static function responder(int $status, string $mensagem): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
