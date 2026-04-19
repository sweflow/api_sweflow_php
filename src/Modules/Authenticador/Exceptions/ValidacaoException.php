<?php

namespace Src\Modules\Authenticador\Exceptions;

use Exception;

/**
 * Exception: ValidacaoException
 * 
 * Lançada quando há erros de validação de dados
 */
class ValidacaoException extends Exception
{
    private array $errors;
    
    public function __construct(string $message, array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
}
