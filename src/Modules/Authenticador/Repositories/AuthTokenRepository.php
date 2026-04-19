<?php

namespace Src\Modules\Authenticador\Repositories;

use PDO;
use Src\Modules\Authenticador\Entities\AuthToken;

/**
 * Repository: AuthTokenRepository
 * 
 * Gerencia operações de banco de dados para tokens de autenticação
 */
class AuthTokenRepository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Cria um novo token
     */
    public function criar(AuthToken $token): AuthToken
    {
        $sql = "INSERT INTO auth_tokens (
            uuid, usuario_uuid, token_hash, token_type, expires_at,
            ip_address, user_agent, created_at
        ) VALUES (
            :uuid, :usuario_uuid, :token_hash, :token_type, :expires_at,
            :ip_address, :user_agent, :created_at
        )";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uuid' => $this->gerarUuid(),
            ':usuario_uuid' => $token->getUsuarioUuid(),
            ':token_hash' => $token->getTokenHash(),
            ':token_type' => $token->getTokenType(),
            ':expires_at' => $token->getExpiresAt()->format('Y-m-d H:i:s'),
            ':ip_address' => $token->getIpAddress(),
            ':user_agent' => $token->getUserAgent(),
            ':created_at' => $token->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);
        
        $id = $this->pdo->lastInsertId();
        return $this->buscarPorId($id);
    }
    
    /**
     * Busca token por ID
     */
    public function buscarPorId(int $id): ?AuthToken
    {
        $sql = "SELECT * FROM auth_tokens WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? AuthToken::fromArray($data) : null;
    }
    
    /**
     * Busca token por UUID
     */
    public function buscarPorUuid(string $uuid): ?AuthToken
    {
        $sql = "SELECT * FROM auth_tokens WHERE uuid = :uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? AuthToken::fromArray($data) : null;
    }
    
    /**
     * Busca token por hash
     */
    public function buscarPorHash(string $tokenHash): ?AuthToken
    {
        $sql = "SELECT * FROM auth_tokens WHERE token_hash = :token_hash";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? AuthToken::fromArray($data) : null;
    }
    
    /**
     * Busca todos os tokens de um usuário
     */
    public function buscarPorUsuario(string $usuarioUuid, bool $apenasValidos = false): array
    {
        $sql = "SELECT * FROM auth_tokens WHERE usuario_uuid = :usuario_uuid";
        
        if ($apenasValidos) {
            $sql .= " AND revoked = 0 AND expires_at > NOW()";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':usuario_uuid' => $usuarioUuid]);
        
        $tokens = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tokens[] = AuthToken::fromArray($data);
        }
        
        return $tokens;
    }
    
    /**
     * Atualiza um token
     */
    public function atualizar(AuthToken $token): bool
    {
        $sql = "UPDATE auth_tokens SET
            revoked = :revoked,
            revoked_at = :revoked_at,
            revoked_reason = :revoked_reason,
            last_used_at = :last_used_at
        WHERE uuid = :uuid";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':revoked' => $token->isRevoked() ? 1 : 0,
            ':revoked_at' => $token->getRevokedAt()?->format('Y-m-d H:i:s'),
            ':revoked_reason' => $token->getRevokedReason(),
            ':last_used_at' => $token->getLastUsedAt()?->format('Y-m-d H:i:s'),
            ':uuid' => $token->getUuid(),
        ]);
    }
    
    /**
     * Revoga um token
     */
    public function revogar(string $uuid, string $motivo = null): bool
    {
        $sql = "UPDATE auth_tokens SET
            revoked = 1,
            revoked_at = NOW(),
            revoked_reason = :revoked_reason
        WHERE uuid = :uuid";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':uuid' => $uuid,
            ':revoked_reason' => $motivo,
        ]);
    }
    
    /**
     * Revoga todos os tokens de um usuário
     */
    public function revogarTodosDoUsuario(string $usuarioUuid, string $motivo = null): int
    {
        $sql = "UPDATE auth_tokens SET
            revoked = 1,
            revoked_at = NOW(),
            revoked_reason = :revoked_reason
        WHERE usuario_uuid = :usuario_uuid AND revoked = 0";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':usuario_uuid' => $usuarioUuid,
            ':revoked_reason' => $motivo,
        ]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Remove tokens expirados
     */
    public function limparExpirados(): int
    {
        $sql = "DELETE FROM auth_tokens WHERE expires_at < NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Remove tokens revogados antigos (mais de 30 dias)
     */
    public function limparRevogadosAntigos(int $dias = 30): int
    {
        $sql = "DELETE FROM auth_tokens 
                WHERE revoked = 1 
                AND revoked_at < DATE_SUB(NOW(), INTERVAL :dias DAY)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dias' => $dias]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Conta tokens ativos de um usuário
     */
    public function contarAtivos(string $usuarioUuid): int
    {
        $sql = "SELECT COUNT(*) FROM auth_tokens 
                WHERE usuario_uuid = :usuario_uuid 
                AND revoked = 0 
                AND expires_at > NOW()";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':usuario_uuid' => $usuarioUuid]);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Gera um UUID
     */
    private function gerarUuid(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $stmt = $this->pdo->query("SELECT gen_random_uuid()");
            return $stmt->fetchColumn();
        }
        
        // MySQL/MariaDB - gera UUID v4
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
