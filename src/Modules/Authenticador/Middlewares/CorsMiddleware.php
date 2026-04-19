<?php

namespace Src\Modules\Authenticador\Middlewares;

use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;

/**
 * Middleware: CorsMiddleware
 * 
 * Gerencia CORS (Cross-Origin Resource Sharing)
 * Permite que APIs sejam acessadas de diferentes domínios
 */
class CorsMiddleware
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    private bool $allowCredentials;
    private int $maxAge;
    
    /**
     * @param array $allowedOrigins Origens permitidas (ex: ['https://example.com', '*'])
     * @param array $allowedMethods Métodos HTTP permitidos
     * @param array $allowedHeaders Headers permitidos
     * @param bool $allowCredentials Permite envio de cookies/credenciais
     * @param int $maxAge Tempo de cache do preflight em segundos
     */
    public function __construct(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        bool $allowCredentials = true,
        int $maxAge = 86400
    ) {
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
        $this->allowCredentials = $allowCredentials;
        $this->maxAge = $maxAge;
    }
    
    /**
     * Executa o middleware
     */
    public function handle(Request $request, callable $next): Response
    {
        // Obtém a origem da requisição
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Verifica se a origem é permitida
        $allowedOrigin = $this->getAllowedOrigin($origin);
        
        // Se for uma requisição OPTIONS (preflight), responde imediatamente
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return $this->handlePreflight($allowedOrigin);
        }
        
        // Continua para o próximo middleware/controller
        $response = $next($request);
        
        // Adiciona headers CORS na resposta
        $this->addCorsHeaders($response, $allowedOrigin);
        
        return $response;
    }
    
    /**
     * Verifica se a origem é permitida
     */
    private function getAllowedOrigin(string $origin): string
    {
        // Se permite todas as origens
        if (in_array('*', $this->allowedOrigins)) {
            return '*';
        }
        
        // Verifica se a origem está na lista
        if (in_array($origin, $this->allowedOrigins)) {
            return $origin;
        }
        
        // Verifica padrões com wildcard (ex: *.example.com)
        foreach ($this->allowedOrigins as $allowed) {
            if ($this->matchesPattern($origin, $allowed)) {
                return $origin;
            }
        }
        
        // Origem não permitida
        return '';
    }
    
    /**
     * Verifica se a origem corresponde ao padrão
     */
    private function matchesPattern(string $origin, string $pattern): bool
    {
        // Converte padrão para regex
        $regex = '/^' . str_replace(['*', '.'], ['.*', '\.'], $pattern) . '$/';
        return preg_match($regex, $origin) === 1;
    }
    
    /**
     * Trata requisição preflight (OPTIONS)
     */
    private function handlePreflight(string $allowedOrigin): Response
    {
        $headers = [
            'Access-Control-Allow-Origin' => $allowedOrigin ?: 'null',
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age' => $this->maxAge,
        ];
        
        if ($this->allowCredentials && $allowedOrigin !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        
        return Response::json(null, 204, $headers);
    }
    
    /**
     * Adiciona headers CORS na resposta
     */
    private function addCorsHeaders(Response $response, string $allowedOrigin): void
    {
        if ($allowedOrigin) {
            $response->headers['Access-Control-Allow-Origin'] = $allowedOrigin;
            
            if ($this->allowCredentials && $allowedOrigin !== '*') {
                $response->headers['Access-Control-Allow-Credentials'] = 'true';
            }
            
            $response->headers['Access-Control-Expose-Headers'] = implode(', ', [
                'Content-Length',
                'Content-Type',
                'X-RateLimit-Limit',
                'X-RateLimit-Remaining',
                'X-RateLimit-Reset',
            ]);
        }
    }
    
    /**
     * Factory method para criar o middleware a partir do .env
     */
    public static function fromEnv(): self
    {
        $origins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*';
        $allowedOrigins = $origins === '*' ? ['*'] : explode(',', $origins);
        
        return new self(
            array_map('trim', $allowedOrigins),
            ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
            ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
            true,
            86400
        );
    }
}
