<?php

namespace Src\Modules\Usuario\Exceptions;

class InvalidPasswordException extends DomainException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : 'Senha inválida.', $code, $previous);
    }
}
