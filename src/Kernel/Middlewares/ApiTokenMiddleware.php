<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class ApiTokenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Tenta pegar do header X-API-KEY
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        
        // Se não vier no header específico, tenta pegar do Authorization Bearer
        if (empty($apiKey)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (str_starts_with($authHeader, 'Bearer ')) {
                $apiKey = trim(substr($authHeader, 7));
            }
        }

        // A chave "segredo" está na variável JWT_API_SECRET
        $secret = $_ENV['JWT_API_SECRET'] ?? null;

        if (!$secret) {
            return Response::json(['error' => 'API Token não configurado no servidor (JWT_API_SECRET).'], 500);
        }

        // Aqui está o erro de lógica anterior:
        // O cliente envia um JWT assinado usando o segredo.
        // O middleware anterior estava comparando o JWT enviado pelo cliente COM O SEGREDO EM SI.
        // O correto é verificar se o JWT é válido usando o segredo.

        try {
            // Decodifica o token usando a lib firebase/php-jwt
            // Precisamos da classe JWT e Key
            // Como não temos acesso fácil ao container/autoloader aqui dentro sem imports, vamos assumir que JWT está disponível
            // Mas espera, este é um middleware PSR-like.
            
            // Se o input for EXATAMENTE igual ao segredo, permitimos (caso de uso de API Key simples)
            if ($apiKey === $secret) {
                return $next($request);
            }

            // Caso contrário, tentamos validar como JWT
            if (class_exists(\Firebase\JWT\JWT::class) && class_exists(\Firebase\JWT\Key::class)) {
                $decoded = \Firebase\JWT\JWT::decode($apiKey, new \Firebase\JWT\Key($secret, 'HS256'));
                
                // Verifica se é um token de API (campo 'api_access' ou 'tipo'='api')
                if (empty($decoded->api_access) && ($decoded->tipo ?? '') !== 'api') {
                     return Response::json(['error' => 'Token válido, mas não é de API.'], 403);
                }
                
                // Se chegou aqui, é válido
                return $next($request);
            } else {
                // Se não tiver a lib JWT, só suportamos comparação direta
                return Response::json(['error' => 'Biblioteca JWT não disponível.'], 500);
            }
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Token de API inválido ou expirado.'], 401);
        }
    }
}
