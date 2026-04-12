<?php

namespace Task\DTOs;

final class CreateTaskDTO
{
    public function __construct(
        public readonly string $nome,
    ) {}

    public static function fromArray(array $data): self
    {
        $nome = trim($data['nome'] ?? '');
        if ($nome === '') { throw new \InvalidArgumentException('Campo "nome" obrigatorio.'); }
        return new self(nome: $nome);
    }

    public function toArray(): array { return ['nome' => $this->nome]; }
}
