<?php

namespace Src\Kernel\Contracts;

/**
 * Contrato mínimo que o Kernel precisa para autenticar usuários.
 * Os módulos implementam este contrato — o Kernel não depende de módulos.
 *
 * Métodos mínimos para autenticação JWT e busca de usuário por credenciais.
 */
interface UserRepositoryInterface
{
    /** Busca usuário pelo UUID. */
    public function buscarPorUuid(string $uuid): ?object;

    /** Busca usuário pelo e-mail. */
    public function buscarPorEmail(string $email): ?object;

    /** Busca usuário pelo username. */
    public function buscarPorUsername(string $username): ?object;
}
