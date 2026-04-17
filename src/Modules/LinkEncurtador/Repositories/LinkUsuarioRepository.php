<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Repositories;

use PDO;

/**
 * Repositório de usuários do encurtador de links.
 * Completamente isolado da tabela 'usuarios' do kernel.
 */
final class LinkUsuarioRepository
{
    private const TABLE   = 'link_usuarios';
    private const SESSION = 'link_sessoes';

    public function __construct(private readonly PDO $pdo) {}

    // ── Usuários ──────────────────────────────────────────────────────────

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE email = ? AND ativo = TRUE");
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE id = ? AND ativo = TRUE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByGoogleId(string $googleId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE google_id = ? AND ativo = TRUE");
        $stmt->execute([$googleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM " . self::TABLE . " WHERE email = ?");
        $stmt->execute([strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(array $data): array
    {
        $id = $this->generateId();
        $this->pdo->prepare(
            "INSERT INTO " . self::TABLE . " (id, nome, email, senha_hash, google_id, avatar_url)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $id,
            trim($data['nome'] ?? ''),
            strtolower(trim($data['email'])),
            $data['senha_hash'] ?? '',
            $data['google_id'] ?? null,
            $data['avatar_url'] ?? '',
        ]);
        return $this->findById($id) ?? ['id' => $id];
    }

    public function update(string $id, array $data): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now    = $driver === 'pgsql' ? 'NOW()' : 'NOW()';

        $fields = [];
        $params = [];

        if (isset($data['nome']))       { $fields[] = 'nome = ?';       $params[] = trim($data['nome']); }
        if (isset($data['email']))      { $fields[] = 'email = ?';      $params[] = strtolower(trim($data['email'])); }
        if (isset($data['senha_hash'])) { $fields[] = 'senha_hash = ?'; $params[] = $data['senha_hash']; }
        if (isset($data['avatar_url'])) { $fields[] = 'avatar_url = ?'; $params[] = $data['avatar_url']; }
        if (isset($data['google_id']))  { $fields[] = 'google_id = ?';  $params[] = $data['google_id']; }

        if (empty($fields)) return;

        $fields[]  = "atualizado_em = {$now}";
        $params[]  = $id;

        $this->pdo->prepare(
            "UPDATE " . self::TABLE . " SET " . implode(', ', $fields) . " WHERE id = ?"
        )->execute($params);
    }

    // ── Sessões ───────────────────────────────────────────────────────────

    public function createSession(string $userId, int $ttlSeconds = 2592000): string
    {
        // Limpa sessões expiradas do usuário
        $this->cleanExpiredSessions($userId);

        $token     = bin2hex(random_bytes(32)); // 64 chars hex
        $tokenHash = hash('sha256', $token);
        $id        = $this->generateId();
        $driver    = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $this->pdo->prepare(
                "INSERT INTO " . self::SESSION . " (id, user_id, token_hash, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL '{$ttlSeconds} seconds')"
            )->execute([$id, $userId, $tokenHash]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO " . self::SESSION . " (id, user_id, token_hash, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL {$ttlSeconds} SECOND))"
            )->execute([$id, $userId, $tokenHash]);
        }

        return $token; // retorna o token raw (não o hash)
    }

    public function validateSession(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            "SELECT s.user_id, u.id, u.nome, u.email, u.avatar_url, u.google_id
             FROM " . self::SESSION . " s
             JOIN " . self::TABLE . " u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.ativo = TRUE"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function revokeSession(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->pdo->prepare("DELETE FROM " . self::SESSION . " WHERE token_hash = ?")->execute([$tokenHash]);
    }

    public function revokeAllSessions(string $userId): void
    {
        $this->pdo->prepare("DELETE FROM " . self::SESSION . " WHERE user_id = ?")->execute([$userId]);
    }

    private function cleanExpiredSessions(string $userId): void
    {
        $this->pdo->prepare(
            "DELETE FROM " . self::SESSION . " WHERE user_id = ? AND expires_at <= NOW()"
        )->execute([$userId]);
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private function generateId(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            return (string) $this->pdo->query('SELECT gen_random_uuid()')->fetchColumn();
        }
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
