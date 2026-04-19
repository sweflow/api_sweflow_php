<?php

namespace Src\Modules\Authenticador\Entities;

use DateTime;

/**
 * Entity: RateLimit
 * 
 * Representa um registro de rate limit no sistema
 */
class RateLimit
{
    private ?int $id = null;
    private ?string $uuid = null;
    private string $identifier;
    private string $scope;
    private int $requestCount = 0;
    private DateTime $windowStart;
    private DateTime $windowEnd;
    private DateTime $createdAt;
    private DateTime $updatedAt;
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUuid(): ?string { return $this->uuid; }
    public function getIdentifier(): string { return $this->identifier; }
    public function getScope(): string { return $this->scope; }
    public function getRequestCount(): int { return $this->requestCount; }
    public function getWindowStart(): DateTime { return $this->windowStart; }
    public function getWindowEnd(): DateTime { return $this->windowEnd; }
    public function getCreatedAt(): DateTime { return $this->createdAt; }
    public function getUpdatedAt(): DateTime { return $this->updatedAt; }
    
    // Setters
    public function setId(?int $id): self { $this->id = $id; return $this; }
    public function setUuid(?string $uuid): self { $this->uuid = $uuid; return $this; }
    public function setIdentifier(string $identifier): self { $this->identifier = $identifier; return $this; }
    public function setScope(string $scope): self { $this->scope = $scope; return $this; }
    public function setRequestCount(int $requestCount): self { $this->requestCount = $requestCount; return $this; }
    public function setWindowStart(DateTime $windowStart): self { $this->windowStart = $windowStart; return $this; }
    public function setWindowEnd(DateTime $windowEnd): self { $this->windowEnd = $windowEnd; return $this; }
    public function setCreatedAt(DateTime $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function setUpdatedAt(DateTime $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
    
    /**
     * Incrementa o contador de requisições
     */
    public function incrementar(): self
    {
        $this->requestCount++;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Verifica se a janela está ativa
     */
    public function isJanelaAtiva(): bool
    {
        $now = new DateTime();
        return $now >= $this->windowStart && $now <= $this->windowEnd;
    }
    
    /**
     * Verifica se a janela expirou
     */
    public function isJanelaExpirada(): bool
    {
        return new DateTime() > $this->windowEnd;
    }
    
    /**
     * Reseta o contador para uma nova janela
     */
    public function resetar(int $windowSeconds): self
    {
        $this->requestCount = 0;
        $this->windowStart = new DateTime();
        $this->windowEnd = (new DateTime())->modify("+{$windowSeconds} seconds");
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Converte para array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'identifier' => $this->identifier,
            'scope' => $this->scope,
            'request_count' => $this->requestCount,
            'window_start' => $this->windowStart->format('Y-m-d H:i:s'),
            'window_end' => $this->windowEnd->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'is_janela_ativa' => $this->isJanelaAtiva(),
            'is_janela_expirada' => $this->isJanelaExpirada(),
        ];
    }
    
    /**
     * Cria uma instância a partir de um array
     */
    public static function fromArray(array $data): self
    {
        $rateLimit = new self();
        
        if (isset($data['id'])) $rateLimit->setId($data['id']);
        if (isset($data['uuid'])) $rateLimit->setUuid($data['uuid']);
        if (isset($data['identifier'])) $rateLimit->setIdentifier($data['identifier']);
        if (isset($data['scope'])) $rateLimit->setScope($data['scope']);
        if (isset($data['request_count'])) $rateLimit->setRequestCount($data['request_count']);
        if (isset($data['window_start'])) {
            $rateLimit->setWindowStart(new DateTime($data['window_start']));
        }
        if (isset($data['window_end'])) {
            $rateLimit->setWindowEnd(new DateTime($data['window_end']));
        }
        if (isset($data['created_at'])) {
            $rateLimit->setCreatedAt(new DateTime($data['created_at']));
        }
        if (isset($data['updated_at'])) {
            $rateLimit->setUpdatedAt(new DateTime($data['updated_at']));
        }
        
        return $rateLimit;
    }
}
