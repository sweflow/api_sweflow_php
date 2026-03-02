<?php

namespace Modules\Usuario\Exceptions;

class InvalidUsernameException extends \DomainException
{
    public function __construct(string $message = 'Username inválido.', int $code = 0, ?\Throwable $previous = null)
    {
        // Preserve the specific validation message passed by the caller for clearer feedback.
        parent::__construct($message, $code, $previous);
    }
}
