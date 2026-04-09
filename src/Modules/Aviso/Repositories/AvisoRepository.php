<?php

namespace Src\Modules\Aviso\Repositories;

use DateTimeImmutable;
use PDO;
use Src\Modules\Aviso\Entities\Aviso;

class AvisoRepository
{
    public function __construct(private PDO $pdo) {}

    public function salvar(Aviso $aviso): Aviso
    {
        if ($aviso->getId() === null) {
            // INSERT
            $stmt = $this->pdo->prepare(
                'INSERT INTO avisos (titulo, mensagem, tipo, ativo) VALUES (:titulo, :mensagem, :tipo, :ativo)'
            );
            $stmt->execute([
                ':titulo'   => $aviso->getTitulo(),
                ':mensagem' => $aviso->getMensagem(),
                ':tipo'     => $aviso->getTipo(),
                ':ativo'    => $aviso->isAtivo() ? 1 : 0,
            ]);
            return $this->buscarPorId((int) $this->pdo->lastInsertId()) ?? $aviso;
        }

        // UPDATE
        $this->pdo->prepare(
            'UPDATE avisos SET titulo=:titulo, mensagem=:mensagem, tipo=:tipo, ativo=:ativo,
             atualizado_em=CURRENT_TIMESTAMP WHERE id=:id'
        )->execute([
            ':titulo'   => $aviso->getTitulo(),
            ':mensagem' => $aviso->getMensagem(),
            ':tipo'     => $aviso->getTipo(),
            ':ativo'    => $aviso->isAtivo() ? 1 : 0,
            ':id'       => $aviso->getId(),
        ]);

        return $aviso;
    }

    public function buscarPorId(int $id): ?Aviso
    {
        $stmt = $this->pdo->prepare('SELECT * FROM avisos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapear($row) : null;
    }

    /** Retorna apenas avisos ativos — endpoint público. */
    public function listarAtivos(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM avisos WHERE ativo = 1 ORDER BY criado_em DESC');
        return array_map([$this, 'mapear'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Retorna todos os avisos — endpoint admin. */
    public function listarTodos(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM avisos ORDER BY criado_em DESC');
        return array_map([$this, 'mapear'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function deletar(int $id): void
    {
        $this->pdo->prepare('DELETE FROM avisos WHERE id = :id')->execute([':id' => $id]);
    }

    private function mapear(array $row): Aviso
    {
        return new Aviso(
            (int) $row['id'],
            $row['titulo'],
            $row['mensagem'],
            $row['tipo'],
            (bool) $row['ativo'],
            isset($row['criado_em']) ? new DateTimeImmutable($row['criado_em']) : null
        );
    }
}
