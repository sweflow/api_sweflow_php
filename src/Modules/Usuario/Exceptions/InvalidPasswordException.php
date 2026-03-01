<?php

namespace Modules\Usuario\Exceptions;

class InvalidPasswordException extends DomainException
{
    public function __construct(string $motivo = '', int $code = 0, ?\Throwable $previous = null)
    {
        if (trim($motivo) === '') {
            $message = "Senha inválida.";
        } else {
            $message = "Senha inválida: $motivo";
        }
        parent::__construct($message, $code, $previous);
    }
}
