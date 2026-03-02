<?php

namespace Src\Middlewares;

if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository;
use Src\Modules\Usuario\Repositories\UsuarioRepository;

class AuthHybridMiddleware
{
    public static function handle(): void
    {
        // 1) Tenta cookie auth_token (usuário, JWT_SECRET)
        $cookieToken = $_COOKIE['auth_token'] ?? '';
        $cookieToken = is_string($cookieToken) ? trim($cookieToken) : '';

        // 2) Tenta Authorization: Bearer <token> (usuário, JWT_SECRET)
        $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $bearerToken = '';
        if (preg_match('/Bearer\s+(.*)/i', $bearer, $m)) {
            $bearerToken = trim($m[1]);
        }

        $token = $cookieToken !== '' ? $cookieToken : $bearerToken;
        if ($token === '') {
            self::responder(401, 'Não autenticado: token ausente.');
        }

        $payload = self::decodificarJwtUsuario($token);
        self::validarClaimsUsuario($payload);

        // Verifica se o access token foi revogado (logout)
        if (self::blacklist()->isRevoked($payload->jti ?? '')) {
            self::responder(401, 'Token revogado. Faça login novamente.');
        }

        $usuario = self::repo()->buscarPorUuid($payload->sub);
        if (!$usuario) {
            self::responder(401, 'Usuário não encontrado.');
        }

        $GLOBALS['__auth_user'] = $usuario;
        $GLOBALS['__auth_payload'] = $payload;
    }

    private static function decodificarJwtUsuario(string $token): object
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

        throw new DomainException('Token inválido ou expirado.');
    }

    private static function validarClaimsUsuario(object $payload): void
    {
        if (!isset($payload->sub)) {
            self::responder(401, 'Token sem identificador de usuário.');
        }

        if (!isset($payload->tipo) || $payload->tipo !== 'user') {
            self::responder(401, 'Token não é de usuário.');
        }

        if (!isset($payload->jti) || trim((string)$payload->jti) === '') {
            self::responder(401, 'Token sem jti.');
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

    private static function repo(): UsuarioRepository
    {
        static $repo = null;
        if ($repo) {
            return $repo;
        }

        $repo = new UsuarioRepository(self::pdo());
        return $repo;
    }

    private static function blacklist(): AccessTokenBlacklistRepository
    {
        static $repo = null;
        if ($repo) {
            return $repo;
        }

        $repo = new AccessTokenBlacklistRepository(self::pdo());
        return $repo;
    }

    private static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
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

        return $pdo;
    }

    private static function responder(int $status, string $mensagem): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
