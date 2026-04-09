<?php

namespace Src\Modules\Aviso\Entities;

use DateTimeImmutable;

final class Aviso
{
    private const TIPOS_VALIDOS = ['info', 'sucesso', 'alerta', 'erro'];

    public function __construct(
        private readonly ?int      $id,           // null = novo aviso (ainda não persistido)
        private string             $titulo,        // Título curto do aviso
        private string             $mensagem,      // Corpo do aviso
        private string             $tipo,          // info | sucesso | alerta | erro
        private bool               $ativo,         // false = aviso desativado/arquivado
        private readonly ?DateTimeImmutable $criadoEm = null
    ) {
        $this->validar();
    }

    // ── Factory ───────────────────────────────────────────────────────────

    public static function criar(string $titulo, string $mensagem, string $tipo = 'info'): self
    {
        return new self(null, trim($titulo), trim($mensagem), $tipo, true);
    }

    // ── Validação ─────────────────────────────────────────────────────────

    private function validar(): void
    {
        if (trim($this->titulo) === '') {
            throw new \InvalidArgumentException('Título é obrigatório.');
        }
        if (trim($this->mensagem) === '') {
            throw new \InvalidArgumentException('Mensagem é obrigatória.');
        }
        if (!in_array($this->tipo, self::TIPOS_VALIDOS, true)) {
            throw new \InvalidArgumentException('Tipo inválido. Use: ' . implode(', ', self::TIPOS_VALIDOS));
        }
    }

    // ── Comportamentos ────────────────────────────────────────────────────

    public function atualizar(string $titulo, string $mensagem, string $tipo): void
    {
        $this->titulo   = trim($titulo);
        $this->mensagem = trim($mensagem);
        $this->tipo     = $tipo;
        $this->validar();
    }

    public function ativar(): void   { $this->ativo = true; }
    public function desativar(): void { $this->ativo = false; }

    // ── Getters ───────────────────────────────────────────────────────────

    public function getId(): ?int                        { return $this->id; }
    public function getTitulo(): string                  { return $this->titulo; }
    public function getMensagem(): string                { return $this->mensagem; }
    public function getTipo(): string                    { return $this->tipo; }
    public function isAtivo(): bool                      { return $this->ativo; }
    public function getCriadoEm(): ?DateTimeImmutable    { return $this->criadoEm; }
}
