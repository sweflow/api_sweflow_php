<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenPayloadInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Contracts\UserResolverInterface;

/**
 * Resolve usuário por UUID via UserRepositoryInterface.
 *
 * Responsabilidade única: buscar o usuário pelo identificador.
 * Não decide sobre inatividade — isso é responsabilidade da IdentityFactory.
 */
final class DatabaseUserResolver implements UserResolverInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $repository
    ) {}

    public function resolve(string $identifier, TokenPayloadInterface $payload): mixed
    {
        return $this->repository->buscarPorUuid($identifier);
    }
}
