<?php

namespace Src\Kernel\Contracts;

/**
 * Contrato mínimo para verificar se um JWT foi revogado.
 * O Kernel usa este contrato — não depende do módulo Auth diretamente.
 */
interface TokenBlacklistInterface
{
    public function isRevoked(string $jti): bool;
}
