<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

/**
 * Contrato tipado para o payload de um token autenticado.
 *
 * Elimina a dependência de stdClass e acesso por ->nivel_acesso ?? null
 * espalhado pelo código. Qualquer implementação de TokenValidatorInterface
 * retorna este contrato em vez de payload solto.
 *
 * Implementações:
 *   - JwtPayload (padrão nativo — wraps stdClass do firebase/php-jwt)
 *   - OAuthPayload (scopes, client_id, etc.)
 *   - ApiKeyPayload (permissões estáticas)
 *   - SessionPayload (dados de sessão PHP)
 */
interface TokenPayloadInterface
{
    /**
     * Identificador do sujeito (sub) — UUID, email, ID numérico, etc.
     * Usado pelo UserResolverInterface para buscar o usuário.
     */
    public function getSubject(): string|int|null;

    /**
     * Papel/nível de acesso declarado no token.
     * Retorna null se o token não carregar informação de role.
     */
    public function getRole(): ?string;

    /**
     * Indica se o token foi assinado com JWT_API_SECRET.
     * Relevante para a verificação de admin no JwtAuthContext.
     */
    public function isSignedWithApiSecret(): bool;

    /**
     * Acesso a claims/campos arbitrários do payload.
     * Escape hatch para implementações que precisam de dados específicos.
     * Retorna null se o campo não existir.
     */
    public function get(string $key): mixed;

    /**
     * Retorna o payload bruto original (stdClass, array, etc.).
     * Low-level escape hatch — preferir os métodos tipados acima.
     */
    public function raw(): mixed;
}
