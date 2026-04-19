<?php

namespace Src\Modules\Authenticador\Exceptions;

/**
 * Exception: CredenciaisInvalidasException
 * 
 * Lançada quando as credenciais fornecidas são inválidas
 */
class CredenciaisInvalidasException extends AuthenticadorException
{
    public function __construct(string $message = "Credenciais inválidas")
    {
        parent::__construct($message, 401);
    }
}
