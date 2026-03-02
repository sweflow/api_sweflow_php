<?php
namespace Src\Middlewares;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Contracts\MiddlewareInterface;
use Src\Http\Request\Request;
use Src\Http\Response\Response;

class RouteProtectionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $roles = $request->attribute('roles', []);

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $userRole = null;
        $jwtValid = false;
        $apiTokenValid = false;
        $isApiToken = false;
        $decodedRaw = null;

        if (preg_match('/Bearer\s+(.*)/i', $authHeader, $matches)) {
            $jwt = $matches[1];
            try {
                $decodedRaw = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]));
                $isApiToken = isset($decodedRaw->api_access) && $decodedRaw->api_access === true;
            } catch (\Exception $e) {
                return Response::json(['error' => 'Token JWT malformado.'], 401);
            }

            $secretApi = $_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET');
            $secretUser = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
            if (!$secretApi || !$secretUser) {
                return Response::json(['error' => 'JWT_API_SECRET ou JWT_SECRET não configurado.'], 500);
            }

            try {
                $decodedApi = null;
                $decodedUser = null;
                $apiValido = false;
                $userValido = false;
                try {
                    $decodedApi = JWT::decode($jwt, new Key($secretApi, 'HS256'));
                    $apiValido = true;
                } catch (\Exception $e) {
                }
                try {
                    $decodedUser = JWT::decode($jwt, new Key($secretUser, 'HS256'));
                    $userValido = true;
                } catch (\Exception $e) {
                }

                if ($apiValido && isset($decodedApi->exp) && $decodedApi->exp < time()) {
                    return Response::json(['error' => 'Token JWT expirado.'], 401);
                }
                if ($userValido && isset($decodedUser->exp) && $decodedUser->exp < time()) {
                    return Response::json(['error' => 'Token JWT expirado.'], 401);
                }

                if ($apiValido && $isApiToken) {
                    $apiTokenValid = true;
                }
                if ($userValido && isset($decodedUser->nivel_acesso)) {
                    $userRole = $decodedUser->nivel_acesso;
                    $jwtValid = true;
                }
            } catch (\Exception $e) {
                return Response::json(['error' => 'Token JWT inválido ou assinatura incorreta.'], 401);
            }
        }

        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $apiKeyEnv = $_ENV['API_KEY'] ?? getenv('API_KEY');
        if (!empty($apiKey) && $apiKey === $apiKeyEnv) {
            $apiTokenValid = true;
        }

        if (!empty($roles)) {
            if (!$jwtValid || !in_array($userRole, $roles, true)) {
                return Response::json(['error' => 'Acesso restrito para este papel ou token inválido.'], 403);
            }
            return $next($request);
        }

        $claimsPermitidas = ['sub', 'exp', 'api_access', 'tipo'];
        if (!$apiTokenValid || !$isApiToken || !isset($decodedRaw->tipo) || $decodedRaw->tipo !== 'api') {
            return Response::json(['error' => 'Acesso negado: apenas token de API permitido nesta rota.'], 403);
        }
        foreach ($decodedRaw as $claim => $valor) {
            if (!in_array($claim, $claimsPermitidas, true)) {
                return Response::json(['error' => 'Acesso negado: apenas token de API puro permitido nesta rota.'], 403);
            }
        }

        return $next($request);
    }
}
