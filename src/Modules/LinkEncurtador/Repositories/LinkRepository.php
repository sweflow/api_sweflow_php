<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Repositories;

use PDO;

final class LinkRepository
{
    private const TABLE        = 'links';
    private const TABLE_CLICKS = 'link_cliques';

    public function __construct(private readonly PDO $pdo) {}

    // ── Queries ───────────────────────────────────────────────────────────

    public function findByUser(string $userId, int $page = 1, int $perPage = 50, string $search = ''): array
    {
        $offset = ($page - 1) * $perPage;
        $where  = 'WHERE user_id = ?';
        $params = [$userId];

        if ($search !== '') {
            $where   .= ' AND (alias ILIKE ? OR url ILIKE ? OR titulo ILIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Fallback para MySQL (sem ILIKE)
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'pgsql' && $search !== '') {
            $where    = 'WHERE user_id = ? AND (alias LIKE ? OR url LIKE ? OR titulo LIKE ?)';
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . " {$where}
             ORDER BY criado_em DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . " {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        return [
            'links'    => array_map([$this, 'formatRow'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    public function findById(string $id, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->formatRow($row) : null;
    }

    public function findByAlias(string $alias): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE alias = ?"
        );
        $stmt->execute([$alias]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->formatRow($row) : null;
    }

    public function aliasExists(string $alias, ?string $excludeId = null): bool
    {
        $sql    = "SELECT 1 FROM " . self::TABLE . " WHERE alias = ?";
        $params = [$alias];
        if ($excludeId !== null) {
            $sql    .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public function statsForUser(string $userId): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ativo = TRUE  AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END) AS ativos,
                SUM(CASE WHEN ativo = FALSE OR  (expires_at IS NOT NULL AND expires_at <= ?) THEN 1 ELSE 0 END) AS expirados,
                COALESCE(SUM(cliques), 0) AS total_cliques
             FROM " . self::TABLE . "
             WHERE user_id = ?"
        );
        $stmt->execute([$now, $now, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total'         => (int) ($row['total']         ?? 0),
            'ativos'        => (int) ($row['ativos']        ?? 0),
            'expirados'     => (int) ($row['expirados']     ?? 0),
            'total_cliques' => (int) ($row['total_cliques'] ?? 0),
        ];
    }

    public function clicksPerDay(string $linkId, int $days = 7): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql = "SELECT DATE(clicado_em) AS dia, COUNT(*) AS cliques
                    FROM " . self::TABLE_CLICKS . "
                    WHERE link_id = ? AND clicado_em >= NOW() - INTERVAL '{$days} days'
                    GROUP BY dia ORDER BY dia ASC";
        } else {
            $sql = "SELECT DATE(clicado_em) AS dia, COUNT(*) AS cliques
                    FROM " . self::TABLE_CLICKS . "
                    WHERE link_id = ? AND clicado_em >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                    GROUP BY dia ORDER BY dia ASC";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$linkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Mutations ─────────────────────────────────────────────────────────

    public function create(array $data): array
    {
        $id     = $this->generateId();
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $this->pdo->prepare(
                "INSERT INTO " . self::TABLE . "
                 (id, user_id, alias, url, titulo, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $id,
                $data['user_id'],
                $data['alias'],
                $data['url'],
                $data['titulo'] ?? '',
                $data['expires_at'] ?? null,
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO " . self::TABLE . "
                 (id, user_id, alias, url, titulo, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $id,
                $data['user_id'],
                $data['alias'],
                $data['url'],
                $data['titulo'] ?? '',
                $data['expires_at'] ?? null,
            ]);
        }

        return $this->findById($id, $data['user_id']) ?? ['id' => $id];
    }

    public function update(string $id, string $userId, array $data): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now    = $driver === 'pgsql' ? 'NOW()' : 'NOW()';

        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE . "
             SET alias = ?, url = ?, titulo = ?, expires_at = ?, ativo = ?, atualizado_em = {$now}
             WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([
            $data['alias'],
            $data['url'],
            $data['titulo'] ?? '',
            $data['expires_at'] ?? null,
            isset($data['ativo']) ? ($data['ativo'] ? 1 : 0) : 1,
            $id,
            $userId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function delete(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . self::TABLE . " WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function incrementClicks(string $alias, string $ip, string $referrer, string $userAgent): void
    {
        // Busca o link
        $stmt = $this->pdo->prepare("SELECT id FROM " . self::TABLE . " WHERE alias = ?");
        $stmt->execute([$alias]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $linkId = $row['id'];

        // Incrementa contador
        $this->pdo->prepare(
            "UPDATE " . self::TABLE . " SET cliques = cliques + 1 WHERE id = ?"
        )->execute([$linkId]);

        // Registra clique detalhado
        $clickId = $this->generateId();
        $this->pdo->prepare(
            "INSERT INTO " . self::TABLE_CLICKS . " (id, link_id, ip, referrer, user_agent)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$clickId, $linkId, $ip, $referrer, $userAgent]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function formatRow(array $row): array
    {
        $row['cliques'] = (int) $row['cliques'];
        $row['ativo']   = (bool) $row['ativo'];
        return $row;
    }

    private function generateId(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            return (string) $this->pdo->query('SELECT gen_random_uuid()')->fetchColumn();
        }
        // UUID v4 manual para MySQL
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
