<?php

namespace Task\Exceptions;

class TaskException extends \DomainException
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function naoEncontrado(string $recurso = 'Recurso'): self
    {
        return new self($recurso . ' nao encontrado.', 404);
    }

    public static function validacao(string $mensagem): self
    {
        return new self($mensagem, 422);
    }

    public static function naoAutorizado(string $mensagem = 'Acesso nao autorizado.'): self
    {
        return new self($mensagem, 403);
    }
}
