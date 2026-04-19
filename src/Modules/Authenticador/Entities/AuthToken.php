<?php

namespace Src\Modules\Authenticador\Entities;

use DateTime;

/**
 * Entity: AuthToken
 * 
 * Representa um token de autenticação no sistema
 */
class AuthToken
{
    private ?int $id = null;
    private ?string $uuid = null;
    private string $usuarioUuid;
    private string $tokenHash;
    private string $tokenType = 'access';
    private bool $revoked = false;
    private ?DateTime $revokedAt = null;
    private ?string $revokedReason = null;
    private DateTime $expiresAt;
    private DateTime $createdAt;
    private ?DateTime $lastUsedAt = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUuid(): ?string { return $this->uuid; }
    public function getUsuarioUuid(): string { return $this->usuarioUuid; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getTokenType(): string { return $this->tokenType; }
    public function isRevoked(): bool { return $this->revoked; }
    public function getRevokedAt(): ?DateTime { return $this->revokedAt; }
    public function getRevokedReason(): ?string { return $this->revokedReason; }
    public function getExpiresAt(): DateTime { return $this->expiresAt; }
    public function getCreatedAt(): DateTime { return $this->createdAt; }
    public function getLastUsedAt(): ?DateTime { return $this->lastUsedAt; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    
    // Setters
    public function setId(?int $id): self { $this->id = $id; return $this; }
    public function setUuid(?string $uuid): self { $this->uuid = $uuid; return $this; }
    public function setUsuarioUuid(string $usuarioUuid): self { $this->usuarioUuid = $usuarioUuid; return $this; }
    public function setTokenHash(string $tokenHash): self { $this->tokenHash = $tokenHash; return $this; }
    public function setTokenType(string $tokenType): self { $this->tokenType = $tokenType; return $this; }
    public function setRevoked(bool $revoked): self { $this->revoked = $revoked; return $this; }
    public function setRevokedAt(?DateTime $revokedAt): self { $this->revokedAt = $revokedAt; return $this; }
    public function setRevokedReason(?string $revokedReason): self { $this->revokedReason = $revokedReason; return $this; }
    public function setExpiresAt(DateTime $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function setCreatedAt(DateTime $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function setLastUsedAt(?DateTime $lastUsedAt): self { $this->lastUsedAt = $lastUsedAt; return $this; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }
    
    /**
     * Verifica se o token está expirado
     */
    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTime();
    }
    
    /**
     * Verifica se o token é válido (não revogado e não expirado)
     */
    public function isValid(): bool
    {
        return !$this->revoked && !$this->isExpired();
    }
    
    /**
     * Revoga o token
     */
    public function revogar(string $motivo = null): self
    {
        $this->revoked = true;
        $this->revokedAt = new DateTime();
        $this->revokedReason = $motivo;
        return $this;
    }
    
    /**
     * Atualiza o último uso do token
     */
    public function atualizarUltimoUso(): self
    {
        $this->lastUsedAt = new DateTime();
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
            'usuario_uuid' => $this->usuarioUuid,
            'token_type' => $this->tokenType,
            'revoked' => $this->revoked,
            'revoked_at' => $this->revokedAt?->format('Y-m-d H:i:s'),
            'revoked_reason' => $this->revokedReason,
            'expires_at' => $this->expiresAt->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'last_used_at' => $this->lastUsedAt?->format('Y-m-d H:i:s'),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'is_valid' => $this->isValid(),
            'is_expired' => $this->isExpired(),
        ];
    }
    
    /**
     * Cria uma instância a partir de um array
     */
    public static function fromArray(array $data): self
    {
        $token = new self();
        
        if (isset($data['id'])) $token->setId($data['id']);
        if (isset($data['uuid'])) $token->setUuid($data['uuid']);
        if (isset($data['usuario_uuid'])) $token->setUsuarioUuid($data['usuario_uuid']);
        if (isset($data['token_hash'])) $token->setTokenHash($data['token_hash']);
        if (isset($data['token_type'])) $token->setTokenType($data['token_type']);
        if (isset($data['revoked'])) $token->setRevoked((bool)$data['revoked']);
        if (isset($data['revoked_at']) && $data['revoked_at']) {
            $token->setRevokedAt(new DateTime($data['revoked_at']));
        }
        if (isset($data['revoked_reason'])) $token->setRevokedReason($data['revoked_reason']);
        if (isset($data['expires_at'])) {
            $token->setExpiresAt(new DateTime($data['expires_at']));
        }
        if (isset($data['created_at'])) {
            $token->setCreatedAt(new DateTime($data['created_at']));
        }
        if (isset($data['last_used_at']) && $data['last_used_at']) {
            $token->setLastUsedAt(new DateTime($data['last_used_at']));
        }
        if (isset($data['ip_address'])) $token->setIpAddress($data['ip_address']);
        if (isset($data['user_agent'])) $token->setUserAgent($data['user_agent']);
        
        return $token;
    }
}
