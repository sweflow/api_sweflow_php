<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Repositories;

use PDO;

/**
 * Gerencia limites de links por usuário.
 *
 * max_links:
 *   -1 = ilimitado
 *    0 = bloqueado (não pode criar nenhum)
 *    N = pode criar até N links no total
 */
final class LinkLimiteRepository
{
    private const TABLE = 'link_limites';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Retorna o limite configurado para o usuário.
     * Se não houver registro, retorna -1 (ilimitado por padrão).
     */
    public function getLimit(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT max_links FROM " . self::TABLE . " WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int) $row['max_links'] : -1;
    }

    /**
     * Define o limite para um usuário.
     * Usa UPSERT para criar ou atualizar.
     */
    public function setLimit(string $userId, int $maxLinks): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $this->pdo->prepare(
                "INSERT INTO " . self::TABLE . " (user_id, max_links)
                 VALUES (?, ?)
                 ON CONFLICT (user_id) DO UPDATE SET max_links = EXCLUDED.max_links, atualizado_em = NOW()"
            )->execute([$userId, $maxLinks]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO " . self::TABLE . " (user_id, max_links)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE max_links = VALUES(max_links), atualizado_em = NOW()"
            )->execute([$userId, $maxLinks]);
        }
    }

    /**
     * Retorna stats de limite para o usuário: limite, total criado, restante, bloqueado.
     */
    public function getLimitStats(string $userId, int $totalLinks): array
    {
        $maxLinks = $this->getLimit($userId);

        if ($maxLinks === -1) {
            return [
                'max_links'  => -1,
                'unlimited'  => true,
                'blocked'    => false,
                'total'      => $totalLinks,
                'remaining'  => null,
            ];
        }

        $remaining = max(0, $maxLinks - $totalLinks);

        return [
            'max_links'  => $maxLinks,
            'unlimited'  => false,
            'blocked'    => $maxLinks === 0,
            'total'      => $totalLinks,
            'remaining'  => $remaining,
            'reached'    => $totalLinks >= $maxLinks,
        ];
    }

    /**
     * Define o limite para TODOS os usuários que possuem links.
     * Busca user_ids distintos na tabela links e aplica o limite para cada um.
     * Retorna o número de usuários atualizados.
     */
    public function setLimitForAll(int $maxLinks): int
    {
        // Busca todos os user_ids distintos que possuem links
        $stmt = $this->pdo->query("SELECT DISTINCT user_id FROM links");
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Também busca user_ids que já têm limite configurado
        $stmt2 = $this->pdo->query("SELECT DISTINCT user_id FROM " . self::TABLE);
        $existingIds = $stmt2->fetchAll(PDO::FETCH_COLUMN);

        $allIds = array_unique(array_merge($userIds, $existingIds));
        $count  = 0;

        foreach ($allIds as $uid) {
            $this->setLimit((string) $uid, $maxLinks);
            $count++;
        }

        return $count;
    }
}
