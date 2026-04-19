<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

/**
 * Interface estratégica para resolução de Tenant.
 * Permite que diferentes estratégias sejam usadas no futuro:
 * - Subdomínio (cliente.saas.com)
 * - Path (/cliente/api)
 * - Header (X-Tenant-ID)
 * - JWT Claim
 */
interface TenantResolverInterface
{
    /**
     * Tenta identificar o Tenant ID a partir da requisição atual.
     * @return string|null Retorna o ID do tenant ou null se não identificado (contexto global/público)
     */
    public function resolve(): ?string;
}