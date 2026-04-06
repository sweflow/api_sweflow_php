<?php

namespace Src\Modules\Auth\Repositories;

use DateTimeImmutable;
use PDO;

class RefreshTokenRepository
{
    private PDO $pdo;
    private string $table = 'refresh_tokens';
    private int $maxPerUser;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $valor = $_ENV['REFRESH_MAX_PER_USER'] ?? (string) getenv('REFRESH_MAX_PER_USER') ?: '5';
        $limite = (int) $valor;
        $this->maxPerUser = $limite > 0 ? $limite : 5;
    }

    public function store(string $jti, string $userUuid, string $hashedToken, DateTimeImmutable $expiresAt): void
    {
        $sql = "INSERT INTO {$this->table} (jti, user_uuid, token_hash, expires_at, revoked) VALUES (:jti, :user_uuid, :token_hash, :expires_at, :revoked)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':jti', $jti);
        $stmt->bindValue(':user_uuid', $userUuid);
        $stmt->bindValue(':token_hash', $hashedToken);
        $stmt->bindValue(':expires_at', $expiresAt->format('Y-m-d H:i:s'));
        $stmt->bindValue(':revoked', false, PDO::PARAM_BOOL);
        $stmt->execute();

        $this->trimForUser($userUuid, $this->maxPerUser);
    }

    public function revokeByJti(string $jti): void
    {
        $sql = "UPDATE {$this->table} SET revoked = :revoked WHERE jti = :jti";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':revoked', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':jti', $jti);
        $stmt->execute();
    }

    public function revokeByUser(string $userUuid): void
    {
        $sql = "UPDATE {$this->table} SET revoked = :revoked WHERE user_uuid = :user_uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':revoked', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':user_uuid', $userUuid);
        $stmt->execute();
    }

    public function findValidByJti(string $jti): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE jti = :jti AND revoked = :revoked AND expires_at > NOW() LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':jti', $jti);
        $stmt->bindValue(':revoked', false, PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function purgeExpired(int $graceSeconds = 86400): void
    {
        $cutoff = (new DateTimeImmutable())->modify('-' . $graceSeconds . ' seconds');
        $sql = "DELETE FROM {$this->table} WHERE expires_at < :cutoff";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff->format('Y-m-d H:i:s'));
        $stmt->execute();
    }

    private function trimForUser(string $userUuid, int $maxTokens): void
    {
        if ($maxTokens < 1) {
            return;
        }

        // MySQL não permite DELETE + subquery na mesma tabela diretamente.
        // Solução: busca os JTIs a manter e deleta os demais em duas queries.
        $selectSql = "SELECT jti FROM {$this->table}
                      WHERE user_uuid = :user_uuid
                      ORDER BY expires_at DESC
                      LIMIT :limite";

        $stmt = $this->pdo->prepare($selectSql);
        $stmt->bindValue(':user_uuid', $userUuid);
        $stmt->bindValue(':limite', $maxTokens, PDO::PARAM_INT);
        $stmt->execute();
        $keep = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($keep)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        $deleteSql = "DELETE FROM {$this->table}
                      WHERE user_uuid = ?
                        AND jti NOT IN ({$placeholders})";

        $deleteStmt = $this->pdo->prepare($deleteSql);
        $deleteStmt->execute(array_merge([$userUuid], $keep));
    }
}
