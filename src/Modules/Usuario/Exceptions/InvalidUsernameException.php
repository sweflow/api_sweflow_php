<?php

namespace Modules\Usuario\Exceptions;

class InvalidUsernameException extends DomainException
{
    public function __construct(string $username = '', int $code = 0, ?\Throwable $previous = null)
    {
        if (trim($username) === '') {
            $message = "Username não informado.";
        } else {
            $message = "Username inválido: $username";
        }
        parent::__construct($message, $code, $previous);
    }
}
