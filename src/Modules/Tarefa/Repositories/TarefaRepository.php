<?php

namespace Src\Modules\Tarefa\Repositories;

use PDO;

final class TarefaRepository
{
    private string $table = 'tarefas';

    public function __construct(private readonly PDO $pdo) {}

    public function findByUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = ? ORDER BY criado_em DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'pgsql'
            ? $this->pdo->query('SELECT gen_random_uuid()')->fetchColumn()
            : bin2hex(random_bytes(16));

        $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, titulo, user_id) VALUES (?, ?, ?)"
        )->execute([$id, $data['titulo'], $data['user_id']]);

        return $this->findById($id, $data['user_id']) ?? ['id' => $id];
    }

    public function update(string $id, string $userId, array $data): void
    {
        $this->pdo->prepare(
            "UPDATE {$this->table} SET titulo = ?, concluida = ? WHERE id = ? AND user_id = ?"
        )->execute([$data['titulo'], $data['concluida'] ? 1 : 0, $id, $userId]);
    }

    public function delete(string $id, string $userId): void
    {
        $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE id = ? AND user_id = ?"
        )->execute([$id, $userId]);
    }
}