<?php

namespace Src\Modules\Usuario\Exceptions;

class InvalidEmailException extends DomainException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : 'E-mail inválido.', $code, $previous);
    }
}