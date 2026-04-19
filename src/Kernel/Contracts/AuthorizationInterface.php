<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Request\Request;

/**
 * Contrato de autorização — separado intencionalmente de AuthContextInterface.
 *
 * Auth ≠ Authorization.
 *   - AuthContextInterface responde: "quem é você?" → AuthIdentityInterface
 *   - AuthorizationInterface responde: "você pode fazer isso?"
 *
 * Expõe dois métodos:
 *   - isAdmin()   → verificação de admin do sistema (usada pelo AdminOnlyMiddleware)
 *   - hasRole()   → verificação genérica de papel (usada pelo AuthIdentity e controllers)
 *
 * Módulos podem substituir completamente a lógica de roles:
 *   $container->bind(AuthorizationInterface::class, MinhaAutorizacao::class, true);
 */
interface AuthorizationInterface
{
    /**
     * Verifica se a identidade tem permissão de administrador do sistema.
     * O Request é passado para contexto adicional (tenant, IP, etc.).
     */
    public function isAdmin(AuthIdentityInterface $identity, Request $request): bool;

    /**
     * Verifica se a identidade possui um dos papéis informados.
     *
     * Separado de isAdmin() para permitir verificações genéricas de role
     * sem acoplamento com a lógica de admin específica da plataforma.
     *
     * Usado pelo AuthIdentity::hasRole() quando um AuthorizationInterface
     * está disponível — substitui a heurística padrão por lógica explícita.
     */
    public function hasRole(AuthIdentityInterface $identity, string ...$roles): bool;
}
