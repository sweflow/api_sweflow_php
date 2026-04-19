<?php

namespace Src\Modules\Usuarios2\Services;

use PDO;
use Src\Modules\Usuarios2\Entities\Usuario2;
use Src\Modules\Usuarios2\Repositories\Usuario2Repository;
use Src\Modules\Usuarios2\Exceptions\UsuarioNaoEncontradoException;
use Src\Modules\Usuarios2\Exceptions\ValidacaoException;

/**
 * Service: Usuario2Service
 * 
 * Responsável pela lógica de negócio relacionada a usuários
 */
class Usuario2Service
{
    private Usuario2Repository $usuarioRepo;
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->usuarioRepo = new Usuario2Repository($pdo);
    }
    
    // ═══════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Busca usuário por UUID
     */
    public function buscarPorUuid(string $uuid): Usuario2
    {
        $usuario = $this->usuarioRepo->buscarPorUuid($uuid);
        
        if (!$usuario) {
            throw new UsuarioNaoEncontradoException();
        }
        
        // Carrega roles e permissões
        $this->carregarRolesEPermissoes($usuario);
        
        return $usuario;
    }
    
    /**
     * Lista usuários com paginação e filtros
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 20): array
    {
        return $this->usuarioRepo->listar($filtros, $pagina, $porPagina);
    }
    
    /**
     * Cria um novo usuário
     */
    public function criar(array $dados, ?string $criadoPor = null): Usuario2
    {
        // Validações
        $this->validarDadosUsuario($dados);
        
        // Verifica duplicidade
        if ($this->usuarioRepo->emailExiste($dados['email'])) {
            throw new ValidacaoException('Email já cadastrado', ['email' => 'Este email já está em uso']);
        }
        
        if ($this->usuarioRepo->usernameExiste($dados['username'])) {
            throw new ValidacaoException('Username já cadastrado', ['username' => 'Este username já está em uso']);
        }
        
        // Cria o usuário
        $usuario = new Usuario2();
        $usuario->setNomeCompleto($dados['nome_completo']);
        $usuario->setUsername($dados['username']);
        $usuario->setEmail($dados['email']);
        $usuario->setSenhaHash(password_hash($dados['senha'], PASSWORD_ARGON2ID));
        $usuario->setNivelAcesso($dados['nivel_acesso'] ?? 'usuario');
        $usuario->setAtivo($dados['ativo'] ?? true);
        $usuario->setCriadoPor($criadoPor);
        
        // Campos opcionais
        if (isset($dados['url_avatar'])) $usuario->setUrlAvatar($dados['url_avatar']);
        if (isset($dados['url_capa'])) $usuario->setUrlCapa($dados['url_capa']);
        if (isset($dados['biografia'])) $usuario->setBiografia($dados['biografia']);
        if (isset($dados['telefone'])) $usuario->setTelefone($dados['telefone']);
        if (isset($dados['data_nascimento'])) $usuario->setDataNascimento($dados['data_nascimento']);
        
        return $this->usuarioRepo->criar($usuario);
    }
    
    /**
     * Atualiza um usuário
     */
    public function atualizar(string $uuid, array $dados, ?string $atualizadoPor = null): Usuario2
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        // Validações
        $this->validarDadosUsuario($dados, $uuid);
        
        // Verifica duplicidade (excluindo o próprio usuário)
        if (isset($dados['email']) && $this->usuarioRepo->emailExiste($dados['email'], $uuid)) {
            throw new ValidacaoException('Email já cadastrado', ['email' => 'Este email já está em uso']);
        }
        
        if (isset($dados['username']) && $this->usuarioRepo->usernameExiste($dados['username'], $uuid)) {
            throw new ValidacaoException('Username já cadastrado', ['username' => 'Este username já está em uso']);
        }
        
        // Atualiza os campos
        if (isset($dados['nome_completo'])) $usuario->setNomeCompleto($dados['nome_completo']);
        if (isset($dados['username'])) $usuario->setUsername($dados['username']);
        if (isset($dados['email'])) $usuario->setEmail($dados['email']);
        if (isset($dados['url_avatar'])) $usuario->setUrlAvatar($dados['url_avatar']);
        if (isset($dados['url_capa'])) $usuario->setUrlCapa($dados['url_capa']);
        if (isset($dados['biografia'])) $usuario->setBiografia($dados['biografia']);
        if (isset($dados['telefone'])) $usuario->setTelefone($dados['telefone']);
        if (isset($dados['data_nascimento'])) $usuario->setDataNascimento($dados['data_nascimento']);
        if (isset($dados['nivel_acesso'])) $usuario->setNivelAcesso($dados['nivel_acesso']);
        if (isset($dados['ativo'])) $usuario->setAtivo($dados['ativo']);
        
        // Preferências e metadata
        if (isset($dados['preferencias'])) $usuario->setPreferencias($dados['preferencias']);
        if (isset($dados['metadata'])) $usuario->setMetadata($dados['metadata']);
        
        $usuario->setAtualizadoPor($atualizadoPor);
        
        $this->usuarioRepo->atualizar($usuario);
        
        return $usuario;
    }
    
    /**
     * Atualiza senha do usuário
     */
    public function atualizarSenha(string $uuid, string $senhaAtual, string $novaSenha, ?string $atualizadoPor = null): bool
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        // Verifica senha atual
        if (!password_verify($senhaAtual, $usuario->getSenhaHash())) {
            throw new ValidacaoException('Senha atual incorreta');
        }
        
        // Valida nova senha
        $this->validarSenha($novaSenha);
        
        // Atualiza
        $senhaHash = password_hash($novaSenha, PASSWORD_ARGON2ID);
        return $this->usuarioRepo->atualizarSenha($uuid, $senhaHash, $atualizadoPor);
    }
    
    /**
     * Deleta um usuário (soft delete)
     */
    public function deletar(string $uuid, ?string $deletadoPor = null): bool
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        // Não permite deletar super_admin
        if ($usuario->getNivelAcesso() === 'super_admin') {
            throw new ValidacaoException('Não é possível deletar um super administrador');
        }
        
        return $this->usuarioRepo->deletar($uuid, $deletadoPor);
    }
    
    /**
     * Restaura um usuário deletado
     */
    public function restaurar(string $uuid): bool
    {
        return $this->usuarioRepo->restaurar($uuid);
    }
    
    // ═══════════════════════════════════════════════════════════
    // BLOQUEIO
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Bloqueia um usuário
     */
    public function bloquear(string $uuid, string $motivo, ?string $bloqueadoAte = null, ?string $bloqueadoPor = null): bool
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        // Não permite bloquear super_admin
        if ($usuario->getNivelAcesso() === 'super_admin') {
            throw new ValidacaoException('Não é possível bloquear um super administrador');
        }
        
        $usuario->setBloqueado(true);
        $usuario->setBloqueadoEm(new \DateTime());
        $usuario->setBloqueadoMotivo($motivo);
        
        if ($bloqueadoAte) {
            $usuario->setBloqueadoAte(new \DateTime($bloqueadoAte));
        }
        
        $usuario->setAtualizadoPor($bloqueadoPor);
        
        return $this->usuarioRepo->atualizar($usuario);
    }
    
    /**
     * Desbloqueia um usuário
     */
    public function desbloquear(string $uuid, ?string $atualizadoPor = null): bool
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        $usuario->setBloqueado(false);
        $usuario->setBloqueadoEm(null);
        $usuario->setBloqueadoMotivo(null);
        $usuario->setBloqueadoAte(null);
        $usuario->setAtualizadoPor($atualizadoPor);
        
        return $this->usuarioRepo->atualizar($usuario);
    }
    
    // ═══════════════════════════════════════════════════════════
    // PERFIL
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Atualiza perfil do usuário (dados públicos)
     */
    public function atualizarPerfil(string $uuid, array $dados): Usuario2
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        // Apenas campos de perfil
        if (isset($dados['nome_completo'])) $usuario->setNomeCompleto($dados['nome_completo']);
        if (isset($dados['biografia'])) $usuario->setBiografia($dados['biografia']);
        if (isset($dados['url_avatar'])) $usuario->setUrlAvatar($dados['url_avatar']);
        if (isset($dados['url_capa'])) $usuario->setUrlCapa($dados['url_capa']);
        if (isset($dados['telefone'])) $usuario->setTelefone($dados['telefone']);
        if (isset($dados['data_nascimento'])) $usuario->setDataNascimento($dados['data_nascimento']);
        
        $this->usuarioRepo->atualizar($usuario);
        
        return $usuario;
    }
    
    /**
     * Atualiza preferências do usuário
     */
    public function atualizarPreferencias(string $uuid, array $preferencias): bool
    {
        $usuario = $this->buscarPorUuid($uuid);
        
        $prefsAtuais = $usuario->getPreferencias() ?? [];
        $prefsNovas = array_merge($prefsAtuais, $preferencias);
        
        $usuario->setPreferencias($prefsNovas);
        
        return $this->usuarioRepo->atualizar($usuario);
    }
    
    // ═══════════════════════════════════════════════════════════
    // ROLES E PERMISSÕES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Carrega roles e permissões do usuário
     */
    private function carregarRolesEPermissoes(Usuario2 $usuario): void
    {
        // Carrega roles
        $sql = "
            SELECT r.* 
            FROM roles r
            INNER JOIN usuario_roles ur ON r.id = ur.role_id
            WHERE ur.usuario_uuid = :uuid
            AND (ur.expira_em IS NULL OR ur.expira_em > NOW())
            AND r.ativo = 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uuid' => $usuario->getUuid()]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $usuario->setRoles($roles);
        
        // Carrega permissões (via roles + diretas)
        $sql = "
            SELECT DISTINCT p.* 
            FROM permissions p
            WHERE p.id IN (
                -- Permissões via roles
                SELECT rp.permission_id
                FROM role_permissions rp
                INNER JOIN usuario_roles ur ON rp.role_id = ur.role_id
                WHERE ur.usuario_uuid = :uuid
                AND (ur.expira_em IS NULL OR ur.expira_em > NOW())
                
                UNION
                
                -- Permissões diretas (grant)
                SELECT up.permission_id
                FROM usuario_permissions up
                WHERE up.usuario_uuid = :uuid
                AND up.tipo = 'grant'
                AND (up.expira_em IS NULL OR up.expira_em > NOW())
            )
            AND p.id NOT IN (
                -- Remove permissões negadas
                SELECT up.permission_id
                FROM usuario_permissions up
                WHERE up.usuario_uuid = :uuid
                AND up.tipo = 'deny'
                AND (up.expira_em IS NULL OR up.expira_em > NOW())
            )
            AND p.ativo = 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uuid' => $usuario->getUuid()]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $usuario->setPermissions($permissions);
    }
    
    // ═══════════════════════════════════════════════════════════
    // VALIDAÇÕES
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Valida dados do usuário
     */
    private function validarDadosUsuario(array $dados, ?string $excluirUuid = null): void
    {
        $erros = [];
        
        if (isset($dados['nome_completo']) && empty($dados['nome_completo'])) {
            $erros['nome_completo'] = 'Nome completo é obrigatório';
        }
        
        if (isset($dados['username'])) {
            if (empty($dados['username'])) {
                $erros['username'] = 'Username é obrigatório';
            } elseif (!preg_match('/^[a-z0-9_]{3,50}$/', $dados['username'])) {
                $erros['username'] = 'Username deve ter entre 3 e 50 caracteres (apenas letras minúsculas, números e underscore)';
            }
        }
        
        if (isset($dados['email'])) {
            if (empty($dados['email'])) {
                $erros['email'] = 'Email é obrigatório';
            } elseif (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
                $erros['email'] = 'Email inválido';
            }
        }
        
        if (isset($dados['senha'])) {
            try {
                $this->validarSenha($dados['senha']);
            } catch (ValidacaoException $e) {
                $erros['senha'] = $e->getMessage();
            }
        }
        
        if (isset($dados['nivel_acesso'])) {
            $niveisValidos = ['usuario', 'moderador', 'admin', 'super_admin'];
            if (!in_array($dados['nivel_acesso'], $niveisValidos)) {
                $erros['nivel_acesso'] = 'Nível de acesso inválido';
            }
        }
        
        if (!empty($erros)) {
            throw new ValidacaoException('Erro de validação', $erros);
        }
    }
    
    /**
     * Valida força da senha
     */
    private function validarSenha(string $senha): void
    {
        if (strlen($senha) < 8) {
            throw new ValidacaoException('A senha deve ter no mínimo 8 caracteres');
        }
        
        if (!preg_match('/[A-Z]/', $senha)) {
            throw new ValidacaoException('A senha deve conter pelo menos uma letra maiúscula');
        }
        
        if (!preg_match('/[a-z]/', $senha)) {
            throw new ValidacaoException('A senha deve conter pelo menos uma letra minúscula');
        }
        
        if (!preg_match('/[0-9]/', $senha)) {
            throw new ValidacaoException('A senha deve conter pelo menos um número');
        }
        
        if (!preg_match('/[^A-Za-z0-9]/', $senha)) {
            throw new ValidacaoException('A senha deve conter pelo menos um caractere especial');
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // ESTATÍSTICAS
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Retorna estatísticas de usuários
     */
    public function estatisticas(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as ativos,
                SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) as inativos,
                SUM(CASE WHEN bloqueado = 1 THEN 1 ELSE 0 END) as bloqueados,
                SUM(CASE WHEN email_verificado = 1 THEN 1 ELSE 0 END) as email_verificados,
                SUM(CASE WHEN mfa_habilitado = 1 THEN 1 ELSE 0 END) as com_2fa,
                SUM(CASE WHEN nivel_acesso = 'usuario' THEN 1 ELSE 0 END) as usuarios,
                SUM(CASE WHEN nivel_acesso = 'moderador' THEN 1 ELSE 0 END) as moderadores,
                SUM(CASE WHEN nivel_acesso = 'admin' THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN nivel_acesso = 'super_admin' THEN 1 ELSE 0 END) as super_admins
            FROM usuarios2
            WHERE deletado_em IS NULL
        ";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
