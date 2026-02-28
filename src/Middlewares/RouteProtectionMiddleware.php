<?php
namespace src\Middlewares;

// Adiciona autoload do Composer para usar firebase/php-jwt
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class RouteProtectionMiddleware
{
    public static function handle()
    {
        // Exemplo de proteção: verifica se existe um token de acesso
        // Proteção JWT
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)/i', $authHeader, $matches)) {
            $jwt = $matches[1];
            $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
            if (!$secret) {
                http_response_code(500);
                echo json_encode(['error' => 'JWT_SECRET não configurado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            try {
                $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
                if (isset($decoded->exp) && $decoded->exp < time()) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Token JWT expirado.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                // Se chegou aqui, JWT é válido
                return;
            } catch (\Exception $e) {
                http_response_code(401);
                echo json_encode(['error' => 'Token JWT inválido ou assinatura incorreta.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        // Fallback: API_KEY
        if (empty($_SERVER['HTTP_X_API_KEY']) || $_SERVER['HTTP_X_API_KEY'] !== ($_ENV['API_KEY'] ?? getenv('API_KEY'))) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Acesso negado à rota protegida.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
