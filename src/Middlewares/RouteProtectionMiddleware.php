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
    public static function handle($roles = [])
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $userRole = null;
        $jwtValid = false;
        $apiKeyValid = false;

        // Tenta validar JWT
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
                if (isset($decoded->nivel_acesso)) {
                    $userRole = $decoded->nivel_acesso;
                }
                $jwtValid = true;
            } catch (\Exception $e) {
                http_response_code(401);
                echo json_encode(['error' => 'Token JWT inválido ou assinatura incorreta.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Tenta validar API_KEY
        if (!$jwtValid) {
            $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
            $apiKeyEnv = $_ENV['API_KEY'] ?? getenv('API_KEY');
            if (!empty($apiKey) && $apiKey === $apiKeyEnv) {
                $userRole = 'api_key';
                $apiKeyValid = true;
            }
        }

        // BLOQUEIO ABSOLUTO: só permite acesso se JWT OU API_KEY forem válidos
        if (!$jwtValid && !$apiKeyValid) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Acesso negado à rota protegida. Nenhuma autenticação válida fornecida.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Validação de papéis
        if (!empty($roles) && (!in_array($userRole, $roles, true))) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso restrito para este papel.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
