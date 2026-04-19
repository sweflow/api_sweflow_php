<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Request\Request;

/**
 * Contrato de resolução de identidade.
 *
 * Responsabilidade única: dado um Request, quem é o usuário?
 *
 * Retorna AuthIdentityInterface — objeto tipado que elimina as strings
 * mágicas 'auth_user' e 'auth_payload' do Request. Qualquer módulo que
 * leia identidade usa este contrato, garantindo consistência.
 *
 * Para substituir o auth nativo, um módulo faz no boot():
 *   $container->bind(AuthContextInterface::class, MeuAuthContext::class, true);
 */
interface AuthContextInterface
{
    /** Chave do atributo de identidade no Request. */
    public const IDENTITY_KEY = 'auth_identity';

    /** Chave legada do objeto de usuário no Request (compatibilidade). */
    public const LEGACY_USER_KEY = 'auth_user';

    /** Chave legada do payload no Request (compatibilidade). */
    public const LEGACY_PAYLOAD_KEY = 'auth_payload';
    /**
     * Resolve a identidade a partir do Request.
     *
     * Retorna AuthIdentityInterface se autenticado, null se não há credencial
     * válida. Nunca lança exceção — falha silenciosa retorna null.
     *
     * O middleware injeta a identidade no Request e decide 401/403.
     */
    public function resolve(Request $request): ?AuthIdentityInterface;

    /**
     * Extrai a identidade já resolvida de um Request.
     * Retorna null se o Request não passou por autenticação.
     */
    public function identity(Request $request): ?AuthIdentityInterface;
}
