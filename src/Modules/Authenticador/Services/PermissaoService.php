<?php

namespace Src\Modules\Authenticador\Services;

use PDO;
use DateTime;
use Src\Modules\Authenticador\Exceptions\ValidacaoException;

/**
 * Service: PermissaoService
 * 
 * Responsável pelo gerenciamento de roles e permissões (RBAC)
 */
class PermissaoService
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════
    // ROLES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Lista todas as roles
     */
    public function listarRoles(): array
    {
        $sql = "SELECT * FROM roles WHERE ativo = 1 ORDER BY nivel DESC, nome ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca role por ID
     */
    public function buscarRolePorId(int $id): ?array
    {
        $sql = "SELECT * FROM roles WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($role) {
            // Carrega permissões da role
            $role['permissions'] = $this->listarPermissoesRole($id);
        }
        
        return $role ?: null;
    }
    
    /**
     * Busca role por slug
     */
    public function buscarRolePorSlug(string $slug): ?array
    {
        $sql = "SELECT * FROM roles WHERE slug = :slug";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($role) {
            $role['permissions'] = $this->listarPermissoesRole($role['id']);
        }
        
        return $role ?: null;
    }
    
    /**
     * Cria uma nova role
     */
    public function criarRole(array $dados, ?string $criadoPor = null): array
    {
        $this->validarDadosRole($dados);
        
        // Verifica duplicidade
        if ($this->roleExiste($dados['slug'])) {
            throw new ValidacaoException('Slug já existe', ['slug' => 'Este slug já está em uso']);
        }
        
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        if ($isPostgres) {
            $sql = "
                INSERT INTO roles (nome, slug, descricao, nivel, ativo, sistema)
                VALUES (:nome, :slug, :descricao, :nivel, TRUE, FALSE)
                RETURNING id, uuid
            ";
        } else {
            $sql = "
                INSERT INTO roles (uuid, nome, slug, descricao, nivel, ativo, sistema)
                VALUES (UUID(), :nome, :slug, :descricao, :nivel, 1, 0)
            ";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'nome' => $dados['nome'],
            'slug' => $dados['slug'],
            'descricao' => $dados['descricao'] ?? null,
            'nivel' => $dados['nivel'] ?? 10,
        ]);
        
        if ($isPostgres) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $roleId = $row['id'];
        } else {
            $roleId = $this->pdo->lastInsertId();
        }
        
        return $this->buscarRolePorId($roleId);
    }
    
    /**
     * Atualiza uma role
     */
    public function atualizarRole(int $id, array $dados): array
    {
        $role = $this->buscarRolePorId($id);
        
        if (!$role) {
            throw new ValidacaoException('Role não encontrada');
        }
        
        // Não permite editar roles do sistema
        if ($role['sistema']) {
            throw new ValidacaoException('Não é possível editar roles do sistema');
        }
        
        $this->validarDadosRole($dados, $id);
        
        $sql = "
            UPDATE roles SET
                nome = :nome,
                slug = :slug,
                descricao = :descricao,
                nivel = :nivel,
                atualizado_em = NOW()
            WHERE id = :id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'nome' => $dados['nome'],
            'slug' => $dados['slug'],
            'descricao' => $dados['descricao'] ?? null,
            'nivel' => $dados['nivel'] ?? $role['nivel'],
            'id' => $id,
        ]);
        
        return $this->buscarRolePorId($id);
    }
    
    /**
     * Deleta uma role
     */
    public function deletarRole(int $id): bool
    {
        $role = $this->buscarRolePorId($id);
        
        if (!$role) {
            throw new ValidacaoException('Role não encontrada');
        }
        
        // Não permite deletar roles do sistema
        if ($role['sistema']) {
            throw new ValidacaoException('Não é possível deletar roles do sistema');
        }
        
        $sql = "DELETE FROM roles WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }
    
    // ═══════════════════════════════════════════════════════════
    // PERMISSIONS
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Lista todas as permissões
     */
    public function listarPermissoes(?string $modulo = null, ?string $categoria = null): array
    {
        $where = ['ativo = 1'];
        $params = [];
        
        if ($modulo) {
            $where[] = 'modulo = :modulo';
            $params['modulo'] = $modulo;
        }
        
        if ($categoria) {
            $where[] = 'categoria = :categoria';
            $params['categoria'] = $categoria;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT * FROM permissions WHERE {$whereClause} ORDER BY modulo, categoria, nome";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Lista permissões de uma role
     */
    public function listarPermissoesRole(int $roleId): array
    {
        $sql = "
            SELECT p.* 
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
            AND p.ativo = 1
            ORDER BY p.modulo, p.categoria, p.nome
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['role_id' => $roleId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atribui permissões a uma role
     */
    public function atribuirPermissoesRole(int $roleId, array $permissionIds, ?string $concedidoPor = null): bool
    {
        // Remove permissões antigas
        $sql = "DELETE FROM role_permissions WHERE role_id = :role_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['role_id' => $roleId]);
        
        // Adiciona novas permissões
        if (!empty($permissionIds)) {
            $sql = "
                INSERT INTO role_permissions (role_id, permission_id, concedido_por)
                VALUES (:role_id, :permission_id, :concedido_por)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($permissionIds as $permissionId) {
                $stmt->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'concedido_por' => $concedidoPor,
                ]);
            }
        }
        
        return true;
    }
    
    // ═══════════════════════════════════════════════════════════
    // USUÁRIO ↔ ROLES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Lista roles de um usuário
     */
    public function listarRolesUsuario(string $usuarioUuid): array
    {
        $sql = "
            SELECT r.*, ur.atribuido_em, ur.expira_em
            FROM roles r
            INNER JOIN usuario_roles ur ON r.id = ur.role_id
            WHERE ur.usuario_uuid = :usuario_uuid
            AND (ur.expira_em IS NULL OR ur.expira_em > NOW())
            AND r.ativo = 1
            ORDER BY r.nivel DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['usuario_uuid' => $usuarioUuid]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atribui role a um usuário
     */
    public function atribuirRole(string $usuarioUuid, int $roleId, ?string $atribuidoPor = null, ?string $expiraEm = null): bool
    {
        // Verifica se já tem a role
        $sql = "
            SELECT COUNT(*) as total 
            FROM usuario_roles 
            WHERE usuario_uuid = :usuario_uuid AND role_id = :role_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'role_id' => $roleId,
        ]);
        
        if ($stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
            throw new ValidacaoException('Usuário já possui esta role');
        }
        
        // Atribui
        $sql = "
            INSERT INTO usuario_roles (usuario_uuid, role_id, atribuido_por, expira_em)
            VALUES (:usuario_uuid, :role_id, :atribuido_por, :expira_em)
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'role_id' => $roleId,
            'atribuido_por' => $atribuidoPor,
            'expira_em' => $expiraEm,
        ]);
    }
    
    /**
     * Remove role de um usuário
     */
    public function removerRole(string $usuarioUuid, int $roleId): bool
    {
        $sql = "
            DELETE FROM usuario_roles 
            WHERE usuario_uuid = :usuario_uuid AND role_id = :role_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'role_id' => $roleId,
        ]);
    }
    
    /**
     * Sincroniza roles de um usuário (remove antigas e adiciona novas)
     */
    public function sincronizarRoles(string $usuarioUuid, array $roleIds, ?string $atribuidoPor = null): bool
    {
        // Remove todas as roles antigas
        $sql = "DELETE FROM usuario_roles WHERE usuario_uuid = :usuario_uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['usuario_uuid' => $usuarioUuid]);
        
        // Adiciona novas roles
        if (!empty($roleIds)) {
            $sql = "
                INSERT INTO usuario_roles (usuario_uuid, role_id, atribuido_por)
                VALUES (:usuario_uuid, :role_id, :atribuido_por)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($roleIds as $roleId) {
                $stmt->execute([
                    'usuario_uuid' => $usuarioUuid,
                    'role_id' => $roleId,
                    'atribuido_por' => $atribuidoPor,
                ]);
            }
        }
        
        return true;
    }
    
    // ═══════════════════════════════════════════════════════════
    // USUÁRIO ↔ PERMISSIONS (Diretas)
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Lista permissões diretas de um usuário
     */
    public function listarPermissoesUsuario(string $usuarioUuid): array
    {
        $sql = "
            SELECT p.*, up.tipo, up.concedido_em, up.expira_em
            FROM permissions p
            INNER JOIN usuario_permissions up ON p.id = up.permission_id
            WHERE up.usuario_uuid = :usuario_uuid
            AND (up.expira_em IS NULL OR up.expira_em > NOW())
            AND p.ativo = 1
            ORDER BY p.modulo, p.categoria, p.nome
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['usuario_uuid' => $usuarioUuid]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Concede permissão direta a um usuário
     */
    public function concederPermissao(string $usuarioUuid, int $permissionId, string $tipo = 'grant', ?string $concedidoPor = null, ?string $expiraEm = null): bool
    {
        // Remove permissão existente (se houver)
        $sql = "
            DELETE FROM usuario_permissions 
            WHERE usuario_uuid = :usuario_uuid AND permission_id = :permission_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'permission_id' => $permissionId,
        ]);
        
        // Adiciona nova
        $sql = "
            INSERT INTO usuario_permissions (usuario_uuid, permission_id, tipo, concedido_por, expira_em)
            VALUES (:usuario_uuid, :permission_id, :tipo, :concedido_por, :expira_em)
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'permission_id' => $permissionId,
            'tipo' => $tipo,
            'concedido_por' => $concedidoPor,
            'expira_em' => $expiraEm,
        ]);
    }
    
    /**
     * Remove permissão direta de um usuário
     */
    public function removerPermissao(string $usuarioUuid, int $permissionId): bool
    {
        $sql = "
            DELETE FROM usuario_permissions 
            WHERE usuario_uuid = :usuario_uuid AND permission_id = :permission_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'permission_id' => $permissionId,
        ]);
    }
    
    // ═══════════════════════════════════════════════════════════
    // VERIFICAÇÕES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Verifica se usuário tem uma permissão específica
     */
    public function usuarioTemPermissao(string $usuarioUuid, string $permissionSlug): bool
    {
        $sql = "
            SELECT COUNT(*) as total
            FROM permissions p
            WHERE p.slug = :slug
            AND p.ativo = 1
            AND p.id IN (
                -- Permissões via roles
                SELECT rp.permission_id
                FROM role_permissions rp
                INNER JOIN usuario_roles ur ON rp.role_id = ur.role_id
                WHERE ur.usuario_uuid = :usuario_uuid
                AND (ur.expira_em IS NULL OR ur.expira_em > NOW())
                
                UNION
                
                -- Permissões diretas (grant)
                SELECT up.permission_id
                FROM usuario_permissions up
                WHERE up.usuario_uuid = :usuario_uuid
                AND up.tipo = 'grant'
                AND (up.expira_em IS NULL OR up.expira_em > NOW())
            )
            AND p.id NOT IN (
                -- Remove permissões negadas
                SELECT up.permission_id
                FROM usuario_permissions up
                WHERE up.usuario_uuid = :usuario_uuid
                AND up.tipo = 'deny'
                AND (up.expira_em IS NULL OR up.expira_em > NOW())
            )
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'slug' => $permissionSlug,
            'usuario_uuid' => $usuarioUuid,
        ]);
        
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }
    
    /**
     * Verifica se usuário tem uma role específica
     */
    public function usuarioTemRole(string $usuarioUuid, string $roleSlug): bool
    {
        $sql = "
            SELECT COUNT(*) as total
            FROM roles r
            INNER JOIN usuario_roles ur ON r.id = ur.role_id
            WHERE ur.usuario_uuid = :usuario_uuid
            AND r.slug = :slug
            AND (ur.expira_em IS NULL OR ur.expira_em > NOW())
            AND r.ativo = 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'usuario_uuid' => $usuarioUuid,
            'slug' => $roleSlug,
        ]);
        
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }
    
    /**
     * Verifica se role existe
     */
    private function roleExiste(string $slug, ?int $excluirId = null): bool
    {
        $sql = "SELECT COUNT(*) as total FROM roles WHERE slug = :slug";
        
        if ($excluirId) {
            $sql .= " AND id != :id";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $params = ['slug' => $slug];
        
        if ($excluirId) {
            $params['id'] = $excluirId;
        }
        
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }
    
    // ═══════════════════════════════════════════════════════════
    // VALIDAÇÕES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Valida dados de role
     */
    private function validarDadosRole(array $dados, ?int $excluirId = null): void
    {
        $erros = [];
        
        if (empty($dados['nome'])) {
            $erros['nome'] = 'Nome é obrigatório';
        }
        
        if (empty($dados['slug'])) {
            $erros['slug'] = 'Slug é obrigatório';
        } elseif (!preg_match('/^[a-z0-9_-]+$/', $dados['slug'])) {
            $erros['slug'] = 'Slug deve conter apenas letras minúsculas, números, underscore e hífen';
        } elseif ($this->roleExiste($dados['slug'], $excluirId)) {
            $erros['slug'] = 'Este slug já está em uso';
        }
        
        if (isset($dados['nivel']) && (!is_numeric($dados['nivel']) || $dados['nivel'] < 0)) {
            $erros['nivel'] = 'Nível deve ser um número positivo';
        }
        
        if (!empty($erros)) {
            throw new ValidacaoException('Erro de validação', $erros);
        }
    }
}
