<?php

namespace Src\Modules\Authenticador\Repositories;

use PDO;
use DateTime;
use Src\Modules\Authenticador\Entities\RateLimit;

/**
 * Repository: RateLimitRepository
 * 
 * Gerencia operações de banco de dados para rate limits
 */
class RateLimitRepository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Cria um novo registro de rate limit
     */
    public function criar(RateLimit $rateLimit): RateLimit
    {
        $sql = "INSERT INTO rate_limits (
            uuid, identifier, scope, request_count, 
            window_start, window_end, created_at, updated_at
        ) VALUES (
            :uuid, :identifier, :scope, :request_count,
            :window_start, :window_end, :created_at, :updated_at
        )";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uuid' => $this->gerarUuid(),
            ':identifier' => $rateLimit->getIdentifier(),
            ':scope' => $rateLimit->getScope(),
            ':request_count' => $rateLimit->getRequestCount(),
            ':window_start' => $rateLimit->getWindowStart()->format('Y-m-d H:i:s'),
            ':window_end' => $rateLimit->getWindowEnd()->format('Y-m-d H:i:s'),
            ':created_at' => $rateLimit->getCreatedAt()->format('Y-m-d H:i:s'),
            ':updated_at' => $rateLimit->getUpdatedAt()->format('Y-m-d H:i:s'),
        ]);
        
        $id = $this->pdo->lastInsertId();
        return $this->buscarPorId($id);
    }
    
    /**
     * Busca por ID
     */
    public function buscarPorId(int $id): ?RateLimit
    {
        $sql = "SELECT * FROM rate_limits WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? RateLimit::fromArray($data) : null;
    }
    
    /**
     * Busca por UUID
     */
    public function buscarPorUuid(string $uuid): ?RateLimit
    {
        $sql = "SELECT * FROM rate_limits WHERE uuid = :uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? RateLimit::fromArray($data) : null;
    }
    
    /**
     * Busca por identificador e escopo
     */
    public function buscarPorIdentificadorEscopo(string $identifier, string $scope): ?RateLimit
    {
        $sql = "SELECT * FROM rate_limits 
                WHERE identifier = :identifier 
                AND scope = :scope 
                AND window_end > NOW()
                ORDER BY created_at DESC
                LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':scope' => $scope,
        ]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? RateLimit::fromArray($data) : null;
    }
    
    /**
     * Atualiza um registro
     */
    public function atualizar(RateLimit $rateLimit): bool
    {
        $sql = "UPDATE rate_limits SET
            request_count = :request_count,
            window_start = :window_start,
            window_end = :window_end,
            updated_at = :updated_at
        WHERE uuid = :uuid";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':request_count' => $rateLimit->getRequestCount(),
            ':window_start' => $rateLimit->getWindowStart()->format('Y-m-d H:i:s'),
            ':window_end' => $rateLimit->getWindowEnd()->format('Y-m-d H:i:s'),
            ':updated_at' => $rateLimit->getUpdatedAt()->format('Y-m-d H:i:s'),
            ':uuid' => $rateLimit->getUuid(),
        ]);
    }
    
    /**
     * Incrementa o contador de requisições
     */
    public function incrementar(string $uuid): bool
    {
        $sql = "UPDATE rate_limits SET
            request_count = request_count + 1,
            updated_at = NOW()
        WHERE uuid = :uuid";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':uuid' => $uuid]);
    }
    
    /**
     * Remove registros expirados
     */
    public function limparExpirados(): int
    {
        $sql = "DELETE FROM rate_limits WHERE window_end < NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Remove todos os registros de um identificador
     */
    public function limparPorIdentificador(string $identifier): int
    {
        $sql = "DELETE FROM rate_limits WHERE identifier = :identifier";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Conta requisições ativas de um identificador em um escopo
     */
    public function contarRequisicoes(string $identifier, string $scope): int
    {
        $sql = "SELECT COALESCE(SUM(request_count), 0) FROM rate_limits 
                WHERE identifier = :identifier 
                AND scope = :scope 
                AND window_end > NOW()";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':scope' => $scope,
        ]);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Gera um UUID
     */
    private function gerarUuid(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $stmt = $this->pdo->query("SELECT gen_random_uuid()");
            return $stmt->fetchColumn();
        }
        
        // MySQL/MariaDB - gera UUID v4
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
