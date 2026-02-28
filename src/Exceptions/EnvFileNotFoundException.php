<?php

namespace src\Exceptions;

class EnvFileNotFoundException extends ApplicationException
{
    protected int $statusCode = 500;
    protected string $errorCode = 'ENV_FILE_NOT_FOUND';

    public function __construct(string $path)
    {
        parent::__construct("Arquivo .env não encontrado em: {$path}");
    }
}