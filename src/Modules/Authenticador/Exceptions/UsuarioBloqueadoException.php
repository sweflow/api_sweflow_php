<?php

namespace Src\Modules\Authenticador\Exceptions;

/**
 * Exception: UsuarioBloqueadoException
 * 
 * Lançada quando o usuário está bloqueado
 */
class UsuarioBloqueadoException extends AuthenticadorException
{
    public function __construct(string $message = "Usuário bloqueado")
    {
        parent::__construct($message, 403);
    }
}
