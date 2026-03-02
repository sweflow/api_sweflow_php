<?php

namespace Src\Modules\Auth\Repositories;

use DateTimeImmutable;
use PDO;

class AccessTokenBlacklistRepository
{
    private PDO $pdo;
    private string $table = 'revoked_access_tokens';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function revoke(string $jti, string $userUuid, DateTimeImmutable $expiresAt): void
    {
        $sql = "INSERT INTO {$this->table} (jti, user_uuid, expires_at, created_at) VALUES (:jti, :user_uuid, :expires_at, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':jti', $jti);
        $stmt->bindValue(':user_uuid', $userUuid);
        $stmt->bindValue(':expires_at', $expiresAt->format('Y-m-d H:i:sP'));
        $stmt->execute();
    }

    public function isRevoked(string $jti): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE jti = :jti AND expires_at > NOW() LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':jti', $jti);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    public function purgeExpired(int $graceSeconds = 86400): void
    {
        $cutoff = (new DateTimeImmutable())->modify('-' . $graceSeconds . ' seconds');
        $sql = "DELETE FROM {$this->table} WHERE expires_at < :cutoff";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff->format('Y-m-d H:i:sP'));
        $stmt->execute();
    }
}
