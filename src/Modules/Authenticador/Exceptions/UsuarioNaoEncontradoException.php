<?php

namespace Src\Modules\Authenticador\Exceptions;

/**
 * Exception: UsuarioNaoEncontradoException
 * 
 * Lançada quando o usuário não é encontrado
 */
class UsuarioNaoEncontradoException extends AuthenticadorException
{
    public function __construct(string $message = "Usuário não encontrado")
    {
        parent::__construct($message, 404);
    }
}
