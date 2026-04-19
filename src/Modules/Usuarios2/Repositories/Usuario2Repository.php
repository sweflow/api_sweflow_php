<?php

namespace Src\Modules\Usuarios2\Repositories;

use PDO;
use Src\Modules\Usuarios2\Entities\Usuario2;
use Src\Modules\Usuarios2\Exceptions\UsuarioNaoEncontradoException;

/**
 * Repository: Usuario2Repository
 * 
 * Responsável por todas as operações de banco de dados relacionadas a usuários
 */
class Usuario2Repository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════
    // CREATE
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Cria um novo usuário
     */
    public function criar(Usuario2 $usuario): Usuario2
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        if ($isPostgres) {
            $sql = "
                INSERT INTO usuarios2 (
                    nome_completo, username, email, senha_hash, url_avatar, url_capa,
                    biografia, telefone, data_nascimento, nivel_acesso, ativo,
                    email_verificado, mfa_habilitado, preferencias, metadata,
                    criado_por, criado_em
                ) VALUES (
                    :nome_completo, :username, :email, :senha_hash, :url_avatar, :url_capa,
                    :biografia, :telefone, :data_nascimento, :nivel_acesso, :ativo,
                    :email_verificado, :mfa_habilitado, :preferencias::jsonb, :metadata::jsonb,
                    :criado_por, CURRENT_TIMESTAMP
                )
                RETURNING uuid, criado_em
            ";
        } else {
            $sql = "
                INSERT INTO usuarios2 (
                    uuid, nome_completo, username, email, senha_hash, url_avatar, url_capa,
                    biografia, telefone, data_nascimento, nivel_acesso, ativo,
                    email_verificado, mfa_habilitado, preferencias, metadata,
                    criado_por, criado_em
                ) VALUES (
                    UUID(), :nome_completo, :username, :email, :senha_hash, :url_avatar, :url_capa,
                    :biografia, :telefone, :data_nascimento, :nivel_acesso, :ativo,
                    :email_verificado, :mfa_habilitado, :preferencias, :metadata,
                    :criado_por, NOW()
                )
            ";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'nome_completo' => $usuario->getNomeCompleto(),
            'username' => $usuario->getUsername(),
            'email' => $usuario->getEmail(),
            'senha_hash' => $usuario->getSenhaHash(),
            'url_avatar' => $usuario->getUrlAvatar(),
            'url_capa' => $usuario->getUrlCapa(),
            'biografia' => $usuario->getBiografia(),
            'telefone' => $usuario->getTelefone(),
            'data_nascimento' => $usuario->getDataNascimento()?->format('Y-m-d'),
            'nivel_acesso' => $usuario->getNivelAcesso(),
            'ativo' => $usuario->isAtivo() ? ($isPostgres ? 'TRUE' : 1) : ($isPostgres ? 'FALSE' : 0),
            'email_verificado' => $usuario->isEmailVerificado() ? ($isPostgres ? 'TRUE' : 1) : ($isPostgres ? 'FALSE' : 0),
            'mfa_habilitado' => $usuario->isMfaHabilitado() ? ($isPostgres ? 'TRUE' : 1) : ($isPostgres ? 'FALSE' : 0),
            'preferencias' => $usuario->getPreferencias() ? json_encode($usuario->getPreferencias()) : null,
            'metadata' => $usuario->getMetadata() ? json_encode($usuario->getMetadata()) : null,
            'criado_por' => $usuario->getCriadoPor(),
        ]);
        
        if ($isPostgres) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $usuario->setUuid($row['uuid']);
            $usuario->setCriadoEm($row['criado_em']);
        } else {
            $usuario->setUuid($this->pdo->lastInsertId());
            // Busca o registro criado para pegar o UUID gerado
            $stmt = $this->pdo->prepare("SELECT uuid, criado_em FROM usuarios2 WHERE id = LAST_INSERT_ID()");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $usuario->setUuid($row['uuid']);
                $usuario->setCriadoEm($row['criado_em']);
            }
        }
        
        return $usuario;
    }
    
    // ═══════════════════════════════════════════════════════════
    // READ
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Busca usuário por UUID
     */
    public function buscarPorUuid(string $uuid, bool $incluirDeletados = false): ?Usuario2
    {
        $sql = "SELECT * FROM usuarios2 WHERE uuid = :uuid";
        
        if (!$incluirDeletados) {
            $sql .= " AND deletado_em IS NULL";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? new Usuario2($row) : null;
    }
    
    /**
     * Busca usuário por email
     */
    public function buscarPorEmail(string $email, bool $incluirDeletados = false): ?Usuario2
    {
        $sql = "SELECT * FROM usuarios2 WHERE email = :email";
        
        if (!$incluirDeletados) {
            $sql .= " AND deletado_em IS NULL";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? new Usuario2($row) : null;
    }
    
    /**
     * Busca usuário por username
     */
    public function buscarPorUsername(string $username, bool $incluirDeletados = false): ?Usuario2
    {
        $sql = "SELECT * FROM usuarios2 WHERE username = :username";
        
        if (!$incluirDeletados) {
            $sql .= " AND deletado_em IS NULL";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['username' => strtolower(trim($username))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? new Usuario2($row) : null;
    }
    
    /**
     * Busca usuário por email ou username
     */
    public function buscarPorEmailOuUsername(string $identificador, bool $incluirDeletados = false): ?Usuario2
    {
        $sql = "SELECT * FROM usuarios2 WHERE (email = :identificador OR username = :identificador)";
        
        if (!$incluirDeletados) {
            $sql .= " AND deletado_em IS NULL";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['identificador' => strtolower(trim($identificador))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? new Usuario2($row) : null;
    }
    
    /**
     * Lista todos os usuários com paginação e filtros
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 20): array
    {
        $offset = ($pagina - 1) * $porPagina;
        $where = ['deletado_em IS NULL'];
        $params = [];
        
        // Filtros
        if (!empty($filtros['ativo'])) {
            $where[] = 'ativo = :ativo';
            $params['ativo'] = $filtros['ativo'] === 'true' || $filtros['ativo'] === true ? 1 : 0;
        }
        
        if (!empty($filtros['bloqueado'])) {
            $where[] = 'bloqueado = :bloqueado';
            $params['bloqueado'] = $filtros['bloqueado'] === 'true' || $filtros['bloqueado'] === true ? 1 : 0;
        }
        
        if (!empty($filtros['nivel_acesso'])) {
            $where[] = 'nivel_acesso = :nivel_acesso';
            $params['nivel_acesso'] = $filtros['nivel_acesso'];
        }
        
        if (!empty($filtros['email_verificado'])) {
            $where[] = 'email_verificado = :email_verificado';
            $params['email_verificado'] = $filtros['email_verificado'] === 'true' || $filtros['email_verificado'] === true ? 1 : 0;
        }
        
        if (!empty($filtros['busca'])) {
            $where[] = '(nome_completo LIKE :busca OR email LIKE :busca OR username LIKE :busca)';
            $params['busca'] = '%' . $filtros['busca'] . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Total de registros
        $sqlCount = "SELECT COUNT(*) as total FROM usuarios2 WHERE {$whereClause}";
        $stmtCount = $this->pdo->prepare($sqlCount);
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Registros da página
        $sql = "
            SELECT * FROM usuarios2 
            WHERE {$whereClause}
            ORDER BY criado_em DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $usuarios = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $usuarios[] = new Usuario2($row);
        }
        
        return [
            'dados' => $usuarios,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_paginas' => ceil($total / $porPagina),
        ];
    }
    
    // ═══════════════════════════════════════════════════════════
    // UPDATE
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Atualiza um usuário
     */
    public function atualizar(Usuario2 $usuario): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = "
            UPDATE usuarios2 SET
                nome_completo = :nome_completo,
                username = :username,
                email = :email,
                url_avatar = :url_avatar,
                url_capa = :url_capa,
                biografia = :biografia,
                telefone = :telefone,
                data_nascimento = :data_nascimento,
                nivel_acesso = :nivel_acesso,
                ativo = :ativo,
                bloqueado = :bloqueado,
                bloqueado_motivo = :bloqueado_motivo,
                bloqueado_ate = :bloqueado_ate,
                email_verificado = :email_verificado,
                preferencias = :preferencias,
                metadata = :metadata,
                atualizado_em = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . ",
                atualizado_por = :atualizado_por
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'nome_completo' => $usuario->getNomeCompleto(),
            'username' => $usuario->getUsername(),
            'email' => $usuario->getEmail(),
            'url_avatar' => $usuario->getUrlAvatar(),
            'url_capa' => $usuario->getUrlCapa(),
            'biografia' => $usuario->getBiografia(),
            'telefone' => $usuario->getTelefone(),
            'data_nascimento' => $usuario->getDataNascimento()?->format('Y-m-d'),
            'nivel_acesso' => $usuario->getNivelAcesso(),
            'ativo' => $usuario->isAtivo() ? 1 : 0,
            'bloqueado' => $usuario->isBloqueado() ? 1 : 0,
            'bloqueado_motivo' => $usuario->getBloqueadoMotivo(),
            'bloqueado_ate' => $usuario->getBloqueadoAte()?->format('Y-m-d H:i:s'),
            'email_verificado' => $usuario->isEmailVerificado() ? 1 : 0,
            'preferencias' => $usuario->getPreferencias() ? json_encode($usuario->getPreferencias()) : null,
            'metadata' => $usuario->getMetadata() ? json_encode($usuario->getMetadata()) : null,
            'atualizado_por' => $usuario->getAtualizadoPor(),
            'uuid' => $usuario->getUuid(),
        ]);
    }
    
    /**
     * Atualiza a senha do usuário
     */
    public function atualizarSenha(string $uuid, string $senhaHash, ?string $atualizadoPor = null): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = "
            UPDATE usuarios2 SET
                senha_hash = :senha_hash,
                senha_alterada_em = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . ",
                requer_troca_senha = " . ($isPostgres ? 'FALSE' : '0') . ",
                atualizado_em = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . ",
                atualizado_por = :atualizado_por
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'senha_hash' => $senhaHash,
            'atualizado_por' => $atualizadoPor,
            'uuid' => $uuid,
        ]);
    }
    
    /**
     * Atualiza informações de último login
     */
    public function atualizarUltimoLogin(string $uuid, string $ip, ?string $userAgent = null): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = "
            UPDATE usuarios2 SET
                ultimo_login = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . ",
                ultimo_ip = :ip,
                ultimo_user_agent = :user_agent,
                tentativas_login = 0,
                bloqueio_temporario_ate = NULL
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'ip' => $ip,
            'user_agent' => $userAgent,
            'uuid' => $uuid,
        ]);
    }
    
    /**
     * Incrementa tentativas de login falhas
     */
    public function incrementarTentativasLogin(string $uuid): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = "
            UPDATE usuarios2 SET
                tentativas_login = tentativas_login + 1,
                ultimo_login_falho = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . "
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['uuid' => $uuid]);
    }
    
    /**
     * Bloqueia temporariamente o usuário
     */
    public function bloquearTemporariamente(string $uuid, int $minutos = 30): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = "
            UPDATE usuarios2 SET
                bloqueio_temporario_ate = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . " + INTERVAL '{$minutos} minutes'
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['uuid' => $uuid]);
    }
    
    // ═══════════════════════════════════════════════════════════
    // DELETE
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Soft delete de um usuário
     */
    public function deletar(string $uuid, ?string $deletadoPor = null): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        $sql = "
            UPDATE usuarios2 SET
                deletado_em = " . ($isPostgres ? 'CURRENT_TIMESTAMP' : 'NOW()') . ",
                deletado_por = :deletado_por,
                ativo = " . ($isPostgres ? 'FALSE' : '0') . "
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'deletado_por' => $deletadoPor,
            'uuid' => $uuid,
        ]);
    }
    
    /**
     * Restaura um usuário deletado (soft delete)
     */
    public function restaurar(string $uuid): bool
    {
        $sql = "
            UPDATE usuarios2 SET
                deletado_em = NULL,
                deletado_por = NULL,
                ativo = 1
            WHERE uuid = :uuid
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['uuid' => $uuid]);
    }
    
    /**
     * Delete permanente (use com cuidado!)
     */
    public function deletarPermanentemente(string $uuid): bool
    {
        $sql = "DELETE FROM usuarios2 WHERE uuid = :uuid";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['uuid' => $uuid]);
    }
    
    // ═══════════════════════════════════════════════════════════
    // VERIFICAÇÕES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Verifica se email já existe
     */
    public function emailExiste(string $email, ?string $excluirUuid = null): bool
    {
        $sql = "SELECT COUNT(*) as total FROM usuarios2 WHERE email = :email AND deletado_em IS NULL";
        
        if ($excluirUuid) {
            $sql .= " AND uuid != :uuid";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $params = ['email' => strtolower(trim($email))];
        
        if ($excluirUuid) {
            $params['uuid'] = $excluirUuid;
        }
        
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }
    
    /**
     * Verifica se username já existe
     */
    public function usernameExiste(string $username, ?string $excluirUuid = null): bool
    {
        $sql = "SELECT COUNT(*) as total FROM usuarios2 WHERE username = :username AND deletado_em IS NULL";
        
        if ($excluirUuid) {
            $sql .= " AND uuid != :uuid";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $params = ['username' => strtolower(trim($username))];
        
        if ($excluirUuid) {
            $params['uuid'] = $excluirUuid;
        }
        
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }
}
