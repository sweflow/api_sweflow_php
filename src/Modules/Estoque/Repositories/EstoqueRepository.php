<?php
namespace Src\Modules\Estoque\Repositories;

use Src\Modules\Estoque\Entities\Estoque;
use PDO;

final class EstoqueRepository
{
    private string $table = 'estoque';

    public function __construct(private readonly PDO $pdo) {}

    // =========================
    // HYDRATION
    // =========================
    private function hydrate(array $row): Estoque
    {
        return new Estoque(
            id: $row['id'],
            produto: $row['produto'],
            quantidade: (float) $row['quantidade'],
            criadoEm: new \DateTimeImmutable($row['criado_em']),
            atualizadoEm: isset($row['atualizado_em'])
                ? new \DateTimeImmutable($row['atualizado_em'])
                : null
        );
    }

    // =========================
    // READ
    // =========================
    public function count(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM {$this->table}")
            ->fetchColumn();
    }

    public function findPaginated(int $page = 1, int $perPage = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            ORDER BY criado_em DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([
            $perPage,
            ($page - 1) * $perPage
        ]);

        return array_map(
            fn ($row) => $this->hydrate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findById(string $id): ?Estoque
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table} WHERE id = ?
        ");

        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    // =========================
    // WRITE
    // =========================
    public function save(Estoque $estoque): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sqlDateNow = $driver === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';

        $this->pdo->prepare("
            UPDATE {$this->table}
            SET produto = ?, quantidade = ?, atualizado_em = {$sqlDateNow}
            WHERE id = ?
        ")->execute([
            $estoque->getProduto(),
            $estoque->getQuantidade(),
            $estoque->getId(),
        ]);
    }

    public function create(Estoque $estoque): void
    {
        $this->pdo->prepare("
            INSERT INTO {$this->table}
            (id, produto, quantidade, criado_em)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $estoque->getId(),
            $estoque->getProduto(),
            $estoque->getQuantidade(),
            $estoque->getCriadoEm()->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo
            ->prepare("DELETE FROM {$this->table} WHERE id = ?")
            ->execute([$id]);
    }
}