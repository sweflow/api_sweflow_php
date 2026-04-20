<?php
namespace Src\Modules\Estoque\Entities;

final class Estoque
{
    public function __construct(
        private readonly string $id,
        private string $produto,
        private float $quantidade,
        private readonly \DateTimeImmutable $criadoEm,
        private ?\DateTimeImmutable $atualizadoEm = null,
    ) {}

    // =========================
    // GETTERS
    // =========================
    public function getId(): string { return $this->id; }
    public function getProduto(): string { return $this->produto; }
    public function getQuantidade(): float { return $this->quantidade; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
    public function getAtualizadoEm(): ?\DateTimeImmutable { return $this->atualizadoEm; }

    // =========================
    // COMPORTAMENTO (domínio)
    // =========================
    public function adicionar(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }

        $this->quantidade += $quantidade;
        $this->touch();
    }

    public function remover(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }

        if ($quantidade > $this->quantidade) {
            throw new \DomainException('Estoque insuficiente.');
        }

        $this->quantidade -= $quantidade;
        $this->touch();
    }

    private function touch(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    // =========================
    // SERIALIZAÇÃO
    // =========================
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'produto' => $this->produto,
            'quantidade' => $this->quantidade,
            'criado_em' => $this->criadoEm->format('c'),
            'atualizado_em' => $this->atualizadoEm?->format('c'),
        ];
    }
}