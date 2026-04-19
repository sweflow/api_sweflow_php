<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

/**
 * Valida um token bruto e extrai seu payload tipado.
 *
 * Responsabilidade única: dado um token string, ele é válido?
 * Se sim, retorna TokenPayloadInterface. Se não, retorna null.
 *
 * Não conhece usuário, banco ou identidade — só o token.
 */
interface TokenValidatorInterface
{
    /**
     * Valida o token e retorna o payload tipado, ou null se inválido.
     * Nunca lança exceção — falha silenciosa retorna null.
     */
    public function validate(string $token): ?TokenPayloadInterface;

    /**
     * Indica se o token é um token de API puro (machine-to-machine).
     */
    public function isApiToken(string $token): bool;
}
