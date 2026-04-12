<?php

namespace Task\Repositories;

use PDO;

final class TaskRepository
{
    private string $table = 'task_tasks';

    public function __construct(private readonly PDO $pdo) {}

    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    public function findPaginated(int $page = 1, int $perPage = 20): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} ORDER BY criado_em DESC LIMIT ? OFFSET ?");
        $stmt->execute([$perPage, ($page - 1) * $perPage]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'pgsql'
            ? $this->pdo->query('SELECT gen_random_uuid()')->fetchColumn()
            : bin2hex(random_bytes(16));
        $this->pdo->prepare("INSERT INTO {$this->table} (id, nome) VALUES (?, ?)")->execute([$id, $data['nome'] ?? '']);
        return $this->findById($id) ?? ['id' => $id];
    }

    public function update(string $id, array $data): void
    {
        $allowed = ['nome'];
        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[] = "{$key} = ?";
                $values[] = $value;
            }
        }
        if ($fields === []) { return; }
        $values[] = $id;
        $this->pdo->prepare("UPDATE {$this->table} SET " . implode(', ', $fields) . ", atualizado_em = NOW() WHERE id = ?")->execute($values);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$id]);
    }
}
