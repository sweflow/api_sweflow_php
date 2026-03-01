<?php
namespace Src\Middlewares;

// Adiciona autoload do Composer para usar firebase/php-jwt
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}
use Firebase\JWT\JWT;

class RouteProtectionMiddleware
{
    public static function handle($roles = [])
    {
        error_log('[RouteProtectionMiddleware] Executando handle');
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $userRole = null;
        $jwtValid = false;
        $apiTokenValid = false;

        // Tenta validar JWT
        if (preg_match('/Bearer\s+(.*)/i', $authHeader, $matches)) {
            $jwt = $matches[1];
            $decoded = null;
            $isApiToken = false;
            // Decodifica sem validar para checar claims
            try {
                $decodedRaw = \Firebase\JWT\JWT::jsonDecode(\Firebase\JWT\JWT::urlsafeB64Decode(explode('.', $jwt)[1]));
                $isApiToken = isset($decodedRaw->api_access) && $decodedRaw->api_access === true;
            } catch (\Exception $e) {
                http_response_code(401);
                echo json_encode(['error' => 'Token JWT malformado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // Seleciona segredo conforme tipo de token
            $secretApi = $_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET');
            $secretUser = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
            if (!$secretApi || !$secretUser) {
                http_response_code(500);
                echo json_encode(['error' => 'JWT_API_SECRET ou JWT_SECRET não configurado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            try {
                // Sempre tenta validar com as duas chaves
                $decodedApi = null;
                $decodedUser = null;
                $apiValido = false;
                $userValido = false;
                try {
                    $decodedApi = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key($secretApi, 'HS256'));
                    $apiValido = true;
                } catch (\Exception $e) {}
                try {
                    $decodedUser = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key($secretUser, 'HS256'));
                    $userValido = true;
                } catch (\Exception $e) {}
                if ($apiValido && isset($decodedApi->exp) && $decodedApi->exp < time()) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Token JWT expirado.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if ($userValido && isset($decodedUser->exp) && $decodedUser->exp < time()) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Token JWT expirado.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                // Token de API: claim api_access = true, assinado com JWT_API_SECRET
                if ($apiValido && $isApiToken) {
                    $apiTokenValid = true;
                }
                // Token de usuário: claim nivel_acesso, assinado com JWT_SECRET
                if ($userValido && isset($decodedUser->nivel_acesso)) {
                    $userRole = $decodedUser->nivel_acesso;
                    $jwtValid = true;
                }
            } catch (\Exception $e) {
                http_response_code(401);
                echo json_encode(['error' => 'Token JWT inválido ou assinatura incorreta.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Tenta validar API_KEY (acesso técnico)
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $apiKeyEnv = $_ENV['API_KEY'] ?? getenv('API_KEY');
        if (!empty($apiKey) && $apiKey === $apiKeyEnv) {
            $apiTokenValid = true;
        }

        // Se rota exige papéis, só permite acesso com JWT de usuário válido e papel autorizado
        if (!empty($roles)) {
            if (!$jwtValid || !in_array($userRole, $roles, true)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Acesso restrito para este papel ou token inválido.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            return;
        }

        // Se rota é de API (sem papéis), só permite acesso com token de API válido
        // Só aceita se api_access === true, assinado com JWT_API_SECRET, e NÃO tiver claims extras além de sub, exp, api_access
        $claimsPermitidas = ['sub', 'exp', 'api_access', 'tipo'];
        // Só aceita se for assinado com JWT_API_SECRET, tiver api_access = true, tipo = 'api', e claims permitidas
        if (!$apiTokenValid || !$isApiToken || !isset($decodedRaw->tipo) || $decodedRaw->tipo !== 'api') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Acesso negado: apenas token de API permitido nesta rota.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($decodedRaw as $claim => $valor) {
            if (!in_array($claim, $claimsPermitidas, true)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Acesso negado: apenas token de API puro permitido nesta rota.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
}
