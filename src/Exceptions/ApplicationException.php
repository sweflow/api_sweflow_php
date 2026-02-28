<?php

namespace src\Exceptions;

abstract class ApplicationException extends \RuntimeException
{
    protected int $statusCode = 500;
    protected string $errorCode = 'INTERNAL_ERROR';

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}