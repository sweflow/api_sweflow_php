<?php

namespace src\Exceptions;

use Exception;

class RouteException extends Exception
{
    protected int $status;

    public function __construct(
        string $message = 'Erro de rota',
        int $status = 400,
        ?\Throwable $previous = null
    ) {
        $this->status = $status;
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
