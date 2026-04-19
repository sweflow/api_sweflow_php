<?php

namespace Src\Modules\Authenticador\Middlewares;

use PDO;
use Src\Kernel\Http\Request;
use Src\Kernel\Http\Response;

/**
 * Middleware: RateLimitMiddleware
 * 
 * Limita o número de requisições por IP/usuário em um período de tempo
 * Proteção contra spam e ataques de força bruta
 */
class RateLimitMiddleware
{
    private PDO $pdo;
    private int $maxRequests;
    private int $windowSeconds;
    private string $scope;
    
    /**
     * @param PDO $pdo
     * @param int $maxRequests Número máximo de requisições
     * @param int $windowSeconds Janela de tempo em segundos
     * @param string $scope Escopo do rate limit (ex: 'login', 'api', 'global')
     */
    public function __construct(PDO $pdo, int $maxRequests = 60, int $windowSeconds = 60, string $scope = 'global')
    {
        $this->pdo = $pdo;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->scope = $scope;
    }
    
    /**
     * Executa o middleware
     */
    public function handle(Request $request, callable $next): Response
    {
        // Identifica o cliente (IP ou usuário autenticado)
        $identifier = $this->getIdentifier($request);
        
        // Verifica o rate limit
        $attempts = $this->getAttempts($identifier);
        
        if ($attempts >= $this->maxRequests) {
            $retryAfter = $this->getRetryAfter($identifier);
            
            return Response::json([
                'error' => 'Muitas requisições',
                'message' => 'Você excedeu o limite de requisições. Tente novamente mais tarde.',
                'retry_after' => $retryAfter,
                'limit' => $this->maxRequests,
                'window' => $this->windowSeconds,
            ], 429, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $this->maxRequests,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => time() + $retryAfter,
            ]);
        }
        
        // Registra a tentativa
        $this->recordAttempt($identifier);
        
        // Adiciona headers de rate limit na resposta
        $remaining = $this->maxRequests - $attempts - 1;
        $response = $next($request);
        
        // Adiciona headers informativos
        $response->headers['X-RateLimit-Limit'] = $this->maxRequests;
        $response->headers['X-RateLimit-Remaining'] = max(0, $remaining);
        $response->headers['X-RateLimit-Reset'] = time() + $this->windowSeconds;
        
        return $response;
    }
    
    /**
     * Obtém o identificador do cliente
     */
    private function getIdentifier(Request $request): string
    {
        // Prioriza usuário autenticado
        if (isset($request->usuarioUuid)) {
            return 'user:' . $request->usuarioUuid . ':' . $this->scope;
        }
        
        // Usa IP como fallback
        $ip = $request->getClientIp();
        return 'ip:' . $ip . ':' . $this->scope;
    }
    
    /**
     * Obtém o número de tentativas no período
     */
    private function getAttempts(string $identifier): int
    {
        $key = 'rate_limit:' . $identifier;
        
        // Tenta usar cache (Redis, Memcached, etc) se disponível
        // Por enquanto, usa banco de dados
        
        $sql = "
            SELECT COUNT(*) as total
            FROM rate_limits
            WHERE identifier = :identifier
            AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'identifier' => $identifier,
                'window' => $this->windowSeconds,
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) ($result['total'] ?? 0);
        } catch (\Exception $e) {
            // Se a tabela não existir, cria
            $this->createTableIfNotExists();
            return 0;
        }
    }
    
    /**
     * Registra uma tentativa
     */
    private function recordAttempt(string $identifier): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = $isPostgres
            ? "INSERT INTO rate_limits (identifier, created_at) VALUES (:identifier, CURRENT_TIMESTAMP)"
            : "INSERT INTO rate_limits (identifier, created_at) VALUES (:identifier, NOW())";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['identifier' => $identifier]);
        } catch (\Exception $e) {
            // Ignora erros (tabela pode não existir)
        }
    }
    
    /**
     * Calcula quanto tempo falta para poder tentar novamente
     */
    private function getRetryAfter(string $identifier): int
    {
        $sql = "
            SELECT MIN(TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL :window SECOND))) as retry_after
            FROM rate_limits
            WHERE identifier = :identifier
            AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'identifier' => $identifier,
                'window' => $this->windowSeconds,
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return max(1, (int) ($result['retry_after'] ?? $this->windowSeconds));
        } catch (\Exception $e) {
            return $this->windowSeconds;
        }
    }
    
    /**
     * Cria a tabela de rate limits se não existir
     */
    private function createTableIfNotExists(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        if ($isPostgres) {
            $sql = "
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id SERIAL PRIMARY KEY,
                    identifier VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_identifier (identifier),
                    INDEX idx_created_at (created_at)
                )
            ";
        } else {
            $sql = "
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(255) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_identifier (identifier),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ";
        }
        
        try {
            $this->pdo->exec($sql);
        } catch (\Exception $e) {
            // Ignora se já existir
        }
    }
    
    /**
     * Limpa registros antigos (deve ser executado periodicamente)
     */
    public static function cleanup(PDO $pdo, int $olderThanHours = 24): int
    {
        $sql = "
            DELETE FROM rate_limits
            WHERE created_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)
        ";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['hours' => $olderThanHours]);
            return $stmt->rowCount();
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Factory method para criar o middleware
     */
    public static function create(int $maxRequests = 60, int $windowSeconds = 60, string $scope = 'global'): callable
    {
        return function (PDO $pdo) use ($maxRequests, $windowSeconds, $scope) {
            return new self($pdo, $maxRequests, $windowSeconds, $scope);
        };
    }
}
