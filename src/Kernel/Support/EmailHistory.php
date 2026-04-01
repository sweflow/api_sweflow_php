<?php

namespace Src\Kernel\Support;

use PDO;

/**
 * Persists email history to the database (email_history table).
 * Table is created by migration: src/Kernel/Database/migrations/002_email_tables.sql
 */
class EmailHistory
{
    public function __construct(private ?PDO $pdo = null) {}

    public function save(array $entry): array
    {
        $entry['id']         = bin2hex(random_bytes(8));
        $entry['created_at'] = date('c');

        if ($this->pdo === null) {
            return $entry;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO email_history
                (id, subject, recipients, html, logo_url, status, error, resent_from, created_at)
            VALUES
                (:id, :subject, :recipients, :html, :logo_url, :status, :error, :resent_from, :created_at)
        ");
        $stmt->execute([
            ':id'          => $entry['id'],
            ':subject'     => $entry['subject']     ?? '',
            ':recipients'  => json_encode($entry['recipients'] ?? [], JSON_UNESCAPED_UNICODE),
            ':html'        => $entry['html']         ?? '',
            ':logo_url'    => $entry['logo_url']     ?? null,
            ':status'      => $entry['status']       ?? 'enviado',
            ':error'       => $entry['error']        ?? null,
            ':resent_from' => $entry['resent_from']  ?? null,
            ':created_at'  => $entry['created_at'],
        ]);

        return $entry;
    }

    public function all(string $search = ''): array
    {
        if ($this->pdo === null) {
            return [];
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            // ILIKE for PostgreSQL, LIKE for MySQL
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $op     = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            $stmt   = $this->pdo->prepare(
                "SELECT * FROM email_history
                 WHERE subject {$op} :s OR status {$op} :s OR error {$op} :s
                 ORDER BY created_at DESC LIMIT 500"
            );
            $stmt->execute([':s' => $like]);
        } else {
            $stmt = $this->pdo->query(
                "SELECT * FROM email_history ORDER BY created_at DESC LIMIT 500"
            );
        }

        return array_map([$this, 'decodeRow'], $stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM email_history WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->decodeRow($row) : null;
    }

    public function delete(string $id): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $stmt = $this->pdo->prepare("DELETE FROM email_history WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function decodeRow(array $row): array
    {
        $row['recipients'] = is_string($row['recipients'])
            ? (json_decode($row['recipients'], true) ?? [])
            : ($row['recipients'] ?? []);
        return $row;
    }
}
