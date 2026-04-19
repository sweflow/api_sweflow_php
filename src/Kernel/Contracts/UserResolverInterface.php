<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

/**
 * Resolve o usuário a partir de um identificador extraído do payload.
 *
 * Responsabilidade única: dado um ID e o payload tipado,
 * retorna o objeto de usuário ou null.
 *
 * Recebe TokenPayloadInterface em vez de mixed — sem acesso a stdClass solto.
 */
interface UserResolverInterface
{
    /**
     * Resolve o usuário pelo identificador do payload.
     * Retorna null se não encontrado.
     *
     * @param string                $identifier  getSubject() do payload
     * @param TokenPayloadInterface $payload     Payload completo para contexto adicional
     */
    public function resolve(string $identifier, TokenPayloadInterface $payload): mixed;
}
