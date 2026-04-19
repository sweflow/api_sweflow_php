<?php

namespace Src\Modules\Authenticador\Exceptions;

use Exception;

/**
 * Exception base do módulo Authenticador
 */
class AuthenticadorException extends Exception
{
    protected int $httpCode = 500;
    
    public function __construct(string $message = "", int $httpCode = 500, int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->httpCode = $httpCode;
    }
    
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
