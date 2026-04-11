<?php

namespace Src\Modules\Tarefa\Entities;

final class Tarefa
{
    public function __construct(
        private readonly string $id,
        private string $nome,
        private readonly \DateTimeImmutable $criadoEm,
    ) {}

    public function getId(): string { return $this->id; }
    public function getNome(): string { return $this->nome; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function toArray(): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'criado_em' => $this->criadoEm->format('c')];
    }
}
