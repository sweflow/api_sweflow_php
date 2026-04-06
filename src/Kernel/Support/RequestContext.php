<?php

namespace Src\Kernel\Support;

class RequestContext
{
    private string $requestId;
    private ?string $tenantId = null;
    private ?string $userId = null;
    private array $meta = [];

    public function __construct()
    {
        // Gera um ID único para cada requisição para rastreabilidade (SaaS requirement)
        $this->requestId = bin2hex(random_bytes(16));
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setUserId(?string $userId): void
    {
        $this->userId = $userId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function set(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Retorna contexto para logs estruturados
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'tenant_id'  => $this->tenantId,
            'user_id'    => $this->userId,
            'meta'       => $this->meta ?: null,
        ];
    }
}