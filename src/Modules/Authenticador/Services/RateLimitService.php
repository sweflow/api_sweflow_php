<?php

namespace Src\Modules\Authenticador\Services;

use PDO;
use DateTime;
use Src\Modules\Authenticador\Entities\RateLimit;
use Src\Modules\Authenticador\Repositories\RateLimitRepository;

/**
 * Service: RateLimitService
 * 
 * Gerencia rate limiting de requisições
 */
class RateLimitService
{
    private PDO $pdo;
    private RateLimitRepository $repository;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->repository = new RateLimitRepository($pdo);
    }
    
    /**
     * Verifica se uma requisição pode ser processada
     * 
     * @param string $identifier Identificador (IP, user UUID, etc)
     * @param string $scope Escopo do rate limit
     * @param int $maxRequests Número máximo de requisições
     * @param int $windowSeconds Janela de tempo em segundos
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => DateTime]
     */
    public function verificar(
        string $identifier,
        string $scope,
        int $maxRequests,
        int $windowSeconds
    ): array {
        // Busca rate limit existente
        $rateLimit = $this->repository->buscarPorIdentificadorEscopo($identifier, $scope);
        
        // Se não existe ou expirou, cria um novo
        if (!$rateLimit || $rateLimit->isJanelaExpirada()) {
            $rateLimit = $this->criarNovoRateLimit($identifier, $scope, $windowSeconds);
        }
        
        // Verifica se pode processar
        $requestCount = $rateLimit->getRequestCount();
        $allowed = $requestCount < $maxRequests;
        $remaining = max(0, $maxRequests - $requestCount);
        
        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $rateLimit->getWindowEnd(),
            'limit' => $maxRequests,
            'current' => $requestCount,
        ];
    }
    
    /**
     * Registra uma requisição
     * 
     * @param string $identifier Identificador
     * @param string $scope Escopo
     * @param int $windowSeconds Janela de tempo
     * @return bool
     */
    public function registrar(string $identifier, string $scope, int $windowSeconds): bool
    {
        $rateLimit = $this->repository->buscarPorIdentificadorEscopo($identifier, $scope);
        
        if (!$rateLimit || $rateLimit->isJanelaExpirada()) {
            $rateLimit = $this->criarNovoRateLimit($identifier, $scope, $windowSeconds);
        }
        
        $rateLimit->incrementar();
        return $this->repository->atualizar($rateLimit);
    }
    
    /**
     * Reseta o rate limit de um identificador
     * 
     * @param string $identifier Identificador
     * @param string $scope Escopo (opcional, se não fornecido reseta todos)
     * @return int Número de registros removidos
     */
    public function resetar(string $identifier, string $scope = null): int
    {
        if ($scope) {
            $rateLimit = $this->repository->buscarPorIdentificadorEscopo($identifier, $scope);
            if ($rateLimit) {
                $rateLimit->resetar(60); // Reseta com janela padrão de 60s
                $this->repository->atualizar($rateLimit);
                return 1;
            }
            return 0;
        }
        
        return $this->repository->limparPorIdentificador($identifier);
    }
    
    /**
     * Limpa rate limits expirados
     * 
     * @return int Número de registros removidos
     */
    public function limparExpirados(): int
    {
        return $this->repository->limparExpirados();
    }
    
    /**
     * Obtém estatísticas de rate limit
     * 
     * @param string $identifier Identificador
     * @param string $scope Escopo
     * @return array|null
     */
    public function obterEstatisticas(string $identifier, string $scope): ?array
    {
        $rateLimit = $this->repository->buscarPorIdentificadorEscopo($identifier, $scope);
        
        if (!$rateLimit) {
            return null;
        }
        
        return [
            'identifier' => $rateLimit->getIdentifier(),
            'scope' => $rateLimit->getScope(),
            'request_count' => $rateLimit->getRequestCount(),
            'window_start' => $rateLimit->getWindowStart(),
            'window_end' => $rateLimit->getWindowEnd(),
            'is_janela_ativa' => $rateLimit->isJanelaAtiva(),
            'is_janela_expirada' => $rateLimit->isJanelaExpirada(),
        ];
    }
    
    /**
     * Cria um novo rate limit
     */
    private function criarNovoRateLimit(string $identifier, string $scope, int $windowSeconds): RateLimit
    {
        $now = new DateTime();
        $windowEnd = (clone $now)->modify("+{$windowSeconds} seconds");
        
        $rateLimit = new RateLimit();
        $rateLimit->setIdentifier($identifier)
            ->setScope($scope)
            ->setRequestCount(0)
            ->setWindowStart($now)
            ->setWindowEnd($windowEnd)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        
        return $this->repository->criar($rateLimit);
    }
    
    /**
     * Obtém identificador da requisição atual
     * 
     * @param string $usuarioUuid UUID do usuário (opcional)
     * @return string
     */
    public static function obterIdentificador(string $usuarioUuid = null): string
    {
        // Se tem usuário autenticado, usa o UUID
        if ($usuarioUuid) {
            return "user:{$usuarioUuid}";
        }
        
        // Senão, usa o IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return "ip:{$ip}";
    }
}
