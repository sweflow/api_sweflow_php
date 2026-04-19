<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Request\Request;

/**
 * Contrato de extração de token de um Request.
 *
 * Centraliza a lógica de "de onde vem o token?" em um único lugar,
 * eliminando acoplamento implícito entre middlewares e fontes de token.
 *
 * Implementações possíveis:
 *   - Header Authorization: Bearer (padrão API)
 *   - Cookie auth_token (padrão página)
 *   - Header X-API-KEY (machine-to-machine)
 *   - Query string ?token= (webhooks legados)
 *   - Múltiplas fontes em ordem de prioridade
 *
 * Para substituir:
 *   $container->bind(TokenResolverInterface::class, MeuTokenResolver::class, true);
 */
interface TokenResolverInterface
{
    /**
     * Extrai o token bruto do Request.
     * Retorna string vazia se não encontrar token.
     */
    public function resolve(Request $request): string;
}
