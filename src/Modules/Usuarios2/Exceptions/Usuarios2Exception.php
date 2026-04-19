<?php

namespace Src\Modules\Usuarios2\Exceptions;

use Exception;

/**
 * Exception base do módulo Usuarios2
 */
class Usuarios2Exception extends Exception
{
    protected int $httpCode = 400;
    
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
