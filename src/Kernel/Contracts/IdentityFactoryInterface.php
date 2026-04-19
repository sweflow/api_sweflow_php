<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

/**
 * Cria objetos AuthIdentityInterface a partir de usuário e payload tipados.
 *
 * Responsabilidade única: dado um usuário resolvido e um payload validado,
 * monta o objeto de identidade correto.
 *
 * Recebe TokenPayloadInterface — sem stdClass solto.
 */
interface IdentityFactoryInterface
{
    /**
     * Cria uma identidade para um usuário autenticado.
     *
     * @param mixed                  $user    Objeto de usuário resolvido
     * @param TokenPayloadInterface  $payload Payload tipado e validado
     */
    public function forUser(mixed $user, TokenPayloadInterface $payload): AuthIdentityInterface;

    /**
     * Cria uma identidade para um token de API puro (machine-to-machine).
     */
    public function forApiToken(): AuthIdentityInterface;
}
