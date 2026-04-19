<?php

namespace Src\Modules\Authenticador\Integracao;

use PDO;
use Src\Modules\Usuarios2\Services\Usuario2Service;
use Src\Modules\Usuarios2\Repositories\Usuario2Repository;
use Src\Modules\Usuarios2\Entities\Usuario2;
use Src\Modules\Authenticador\Exceptions\CredenciaisInvalidasException;
use Src\Modules\Authenticador\Exceptions\UsuarioBloqueadoException;
use Src\Modules\Authenticador\Exceptions\ValidacaoException;

/**
 * Integrador: Usuario2Integrador
 * 
 * Camada de integração entre o módulo Authenticador e o módulo Usuarios2
 * Responsável por validar credenciais e gerenciar usuários para autenticação
 */
class Usuario2Integrador
{
    private PDO $pdo;
    private Usuario2Repository $usuarioRepo;
    private Usuario2Service $usuarioService;
    
    // Configurações de segurança
    private const MAX_TENTATIVAS_LOGIN = 5;
    private const TEMPO_BLOQUEIO_MINUTOS = 30;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->usuarioRepo = new Usuario2Repository($pdo);
        $this->usuarioService = new Usuario2Service($pdo);
    }
    
    /**
     * Valida credenciais do usuário
     * 
     * @param string $identificador Email ou username
     * @param string $senha Senha em texto plano
     * @return Usuario2
     * @throws CredenciaisInvalidasException
     * @throws UsuarioBloqueadoException
     */
    public function validarCredenciais(string $identificador, string $senha): Usuario2
    {
        // Busca usuário por email ou username
        $usuario = $this->usuarioRepo->buscarPorEmailOuUsername($identificador);
        
        if (!$usuario) {
            throw new CredenciaisInvalidasException('Credenciais inválidas');
        }
        
        // Verifica se o usuário pode logar
        $podeLogar = $usuario->podeLogar();
        if (!$podeLogar['pode']) {
            throw new UsuarioBloqueadoException($podeLogar['motivo']);
        }
        
        // Verifica a senha
        if (!password_verify($senha, $usuario->getSenhaHash())) {
            $this->usuarioRepo->incrementarTentativasLogin($usuario->getUuid());
            
            // Bloqueia temporariamente após muitas tentativas
            if ($usuario->getTentativasLogin() + 1 >= self::MAX_TENTATIVAS_LOGIN) {
                $this->usuarioRepo->bloquearTemporariamente($usuario->getUuid(), self::TEMPO_BLOQUEIO_MINUTOS);
                throw new UsuarioBloqueadoException('Muitas tentativas de login. Tente novamente em ' . self::TEMPO_BLOQUEIO_MINUTOS . ' minutos');
            }
            
            throw new CredenciaisInvalidasException('Credenciais inválidas');
        }
        
        return $usuario;
    }
    
    /**
     * Busca usuário por UUID
     */
    public function buscarPorUuid(string $uuid): ?Usuario2
    {
        return $this->usuarioRepo->buscarPorUuid($uuid);
    }
    
    /**
     * Busca usuário por email
     */
    public function buscarPorEmail(string $email): ?Usuario2
    {
        return $this->usuarioRepo->buscarPorEmail($email);
    }
    
    /**
     * Atualiza último login do usuário
     */
    public function atualizarUltimoLogin(string $usuarioUuid, string $ip, ?string $userAgent): void
    {
        $this->usuarioRepo->atualizarUltimoLogin($usuarioUuid, $ip, $userAgent);
    }
    
    /**
     * Verifica se usuário tem 2FA habilitado
     */
    public function tem2FAHabilitado(string $usuarioUuid): bool
    {
        $usuario = $this->buscarPorUuid($usuarioUuid);
        return $usuario ? $usuario->isMfaHabilitado() : false;
    }
    
    /**
     * Verifica código 2FA (TOTP)
     */
    public function verificarCodigo2FA(string $usuarioUuid, string $codigo): bool
    {
        $usuario = $this->buscarPorUuid($usuarioUuid);
        
        if (!$usuario) {
            return false;
        }
        
        // Verifica código TOTP
        if ($this->verificarCodigoTOTP($usuario->getMfaSecret(), $codigo)) {
            return true;
        }
        
        // Verifica código de backup
        return $this->verificarCodigoBackup($usuario, $codigo);
    }
    
    /**
     * Registra um novo usuário
     */
    public function registrar(array $dados): Usuario2
    {
        // Validações
        $this->validarDadosRegistro($dados);
        
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
        $usuario->setNivelAcesso('usuario');
        $usuario->setAtivo(true);
        $usuario->setEmailVerificado(false);
        
        // Gera token de verificação de email
        $tokenVerificacao = bin2hex(random_bytes(32));
        $usuario->setTokenVerificacaoEmail($tokenVerificacao);
        $usuario->setTokenVerificacaoExpira((new \DateTime())->modify('+24 hours'));
        
        // Salva no banco
        return $this->usuarioRepo->criar($usuario);
    }
    
    /**
     * Atualiza senha do usuário
     */
    public function atualizarSenha(string $usuarioUuid, string $novaSenha): void
    {
        $this->validarSenha($novaSenha);
        $senhaHash = password_hash($novaSenha, PASSWORD_ARGON2ID);
        $this->usuarioRepo->atualizarSenha($usuarioUuid, $senhaHash);
    }
    
    /**
     * Verifica se email existe
     */
    public function emailExiste(string $email): bool
    {
        return $this->usuarioRepo->emailExiste($email);
    }
    
    /**
     * Busca ou cria usuário a partir de dados OAuth
     * 
     * @param array $oauthData Dados do provedor OAuth (Google, Facebook, GitHub)
     * @return Usuario2
     */
    public function findOrCreateFromOAuth(array $oauthData): Usuario2
    {
        $provider = $oauthData['provider'] ?? null;
        $providerId = $oauthData['provider_id'] ?? null;
        $email = $oauthData['email'] ?? null;
        
        if (!$provider || !$providerId || !$email) {
            throw new ValidacaoException('Dados OAuth incompletos');
        }
        
        // Busca usuário por provider_id
        $usuario = $this->buscarPorOAuthProvider($provider, $providerId);
        
        if ($usuario) {
            // Atualiza avatar se mudou
            if (!empty($oauthData['avatar']) && $usuario->getAvatar() !== $oauthData['avatar']) {
                $this->atualizarAvatar($usuario->getUuid(), $oauthData['avatar']);
                $usuario->setAvatar($oauthData['avatar']);
            }
            return $usuario;
        }
        
        // Busca usuário por email
        $usuario = $this->buscarPorEmail($email);
        
        if ($usuario) {
            // Vincula conta OAuth ao usuário existente
            $this->linkOAuthAccount($usuario->getUuid(), $oauthData);
            return $usuario;
        }
        
        // Cria novo usuário
        return $this->criarUsuarioOAuth($oauthData);
    }
    
    /**
     * Busca usuário por provedor OAuth
     * 
     * @param string $provider Nome do provedor (google, facebook, github)
     * @param string $providerId ID do usuário no provedor
     * @return Usuario2|null
     */
    public function buscarPorOAuthProvider(string $provider, string $providerId): ?Usuario2
    {
        $column = $provider . '_id';
        $sql = "SELECT * FROM usuarios2 WHERE {$column} = :provider_id LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['provider_id' => $providerId]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return Usuario2::fromArray($row);
    }
    
    /**
     * Vincula conta OAuth a usuário existente
     * 
     * @param string $usuarioUuid UUID do usuário
     * @param array $oauthData Dados do provedor OAuth
     * @return void
     */
    public function linkOAuthAccount(string $usuarioUuid, array $oauthData): void
    {
        $provider = $oauthData['provider'] ?? null;
        $providerId = $oauthData['provider_id'] ?? null;
        $avatar = $oauthData['avatar'] ?? null;
        
        if (!$provider || !$providerId) {
            throw new ValidacaoException('Dados OAuth incompletos');
        }
        
        // Verifica se o provider_id já está vinculado a outro usuário
        $existingUser = $this->buscarPorOAuthProvider($provider, $providerId);
        
        if ($existingUser && $existingUser->getUuid() !== $usuarioUuid) {
            throw new ValidacaoException('Esta conta ' . ucfirst($provider) . ' já está vinculada a outro usuário');
        }
        
        $column = $provider . '_id';
        $sql = "UPDATE usuarios2 SET {$column} = :provider_id, oauth_provider = :provider";
        
        if ($avatar) {
            $sql .= ", oauth_avatar = :avatar";
        }
        
        $sql .= " WHERE uuid = :uuid";
        
        $params = [
            'provider_id' => $providerId,
            'provider' => $provider,
            'uuid' => $usuarioUuid,
        ];
        
        if ($avatar) {
            $params['avatar'] = $avatar;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    /**
     * Desvincula conta OAuth do usuário
     * 
     * @param string $usuarioUuid UUID do usuário
     * @param string $provider Nome do provedor (google, facebook, github)
     * @return void
     */
    public function unlinkOAuthAccount(string $usuarioUuid, string $provider): void
    {
        $column = $provider . '_id';
        $sql = "UPDATE usuarios2 SET {$column} = NULL WHERE uuid = :uuid";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uuid' => $usuarioUuid]);
    }
    
    /**
     * Atualiza avatar do usuário
     * 
     * @param string $usuarioUuid UUID do usuário
     * @param string $avatarUrl URL do avatar
     * @return void
     */
    private function atualizarAvatar(string $usuarioUuid, string $avatarUrl): void
    {
        $sql = "UPDATE usuarios2 SET oauth_avatar = :avatar WHERE uuid = :uuid";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'avatar' => $avatarUrl,
            'uuid' => $usuarioUuid,
        ]);
    }
    
    /**
     * Cria novo usuário a partir de dados OAuth
     * 
     * @param array $oauthData Dados do provedor OAuth
     * @return Usuario2
     */
    private function criarUsuarioOAuth(array $oauthData): Usuario2
    {
        $provider = $oauthData['provider'];
        $providerId = $oauthData['provider_id'];
        $email = $oauthData['email'];
        $nomeCompleto = $oauthData['nome_completo'] ?? $email;
        $avatar = $oauthData['avatar'] ?? null;
        $emailVerified = $oauthData['email_verified'] ?? false;
        
        // Gera username único baseado no email
        $username = $this->gerarUsernameUnico($email);
        
        // Cria o usuário
        $usuario = new Usuario2();
        $usuario->setNomeCompleto($nomeCompleto);
        $usuario->setUsername($username);
        $usuario->setEmail($email);
        $usuario->setSenhaHash(''); // OAuth users não têm senha
        $usuario->setNivelAcesso('usuario');
        $usuario->setAtivo(true);
        $usuario->setEmailVerificado($emailVerified);
        
        // Define campos OAuth
        $column = $provider . '_id';
        
        // Salva no banco com campos OAuth
        $sql = "INSERT INTO usuarios2 (
            uuid, nome_completo, username, email, senha_hash, nivel_acesso, 
            ativo, email_verificado, {$column}, oauth_provider, oauth_avatar,
            criado_em, atualizado_em
        ) VALUES (
            :uuid, :nome_completo, :username, :email, :senha_hash, :nivel_acesso,
            :ativo, :email_verificado, :provider_id, :provider, :avatar,
            NOW(), NOW()
        )";
        
        $uuid = $this->gerarUuid();
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uuid' => $uuid,
            'nome_completo' => $nomeCompleto,
            'username' => $username,
            'email' => $email,
            'senha_hash' => '',
            'nivel_acesso' => 'usuario',
            'ativo' => 1,
            'email_verificado' => $emailVerified ? 1 : 0,
            'provider_id' => $providerId,
            'provider' => $provider,
            'avatar' => $avatar,
        ]);
        
        $usuario->setUuid($uuid);
        
        return $usuario;
    }
    
    /**
     * Gera username único baseado no email
     * 
     * @param string $email
     * @return string
     */
    private function gerarUsernameUnico(string $email): string
    {
        $base = strtolower(explode('@', $email)[0]);
        $base = preg_replace('/[^a-z0-9_]/', '_', $base);
        
        $username = $base;
        $counter = 1;
        
        while ($this->usuarioRepo->usernameExiste($username)) {
            $username = $base . '_' . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Gera UUID v4
     * 
     * @return string
     */
    private function gerarUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
    
    // ═══════════════════════════════════════════════════════════
    // MÉTODOS PRIVADOS
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Valida dados de registro
     */
    private function validarDadosRegistro(array $dados): void
    {
        $erros = [];
        
        if (empty($dados['nome_completo'])) {
            $erros['nome_completo'] = 'Nome completo é obrigatório';
        }
        
        if (empty($dados['username'])) {
            $erros['username'] = 'Username é obrigatório';
        } elseif (!preg_match('/^[a-z0-9_]{3,50}$/', $dados['username'])) {
            $erros['username'] = 'Username deve ter entre 3 e 50 caracteres (apenas letras minúsculas, números e underscore)';
        }
        
        if (empty($dados['email'])) {
            $erros['email'] = 'Email é obrigatório';
        } elseif (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            $erros['email'] = 'Email inválido';
        }
        
        if (empty($dados['senha'])) {
            $erros['senha'] = 'Senha é obrigatória';
        } else {
            try {
                $this->validarSenha($dados['senha']);
            } catch (ValidacaoException $e) {
                $erros['senha'] = $e->getMessage();
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
    
    /**
     * Verifica código TOTP (2FA)
     */
    private function verificarCodigoTOTP(?string $secret, string $codigo): bool
    {
        if (!$secret) {
            return false;
        }
        
        // TODO: Implementar verificação TOTP real usando biblioteca spomky-labs/otphp
        return false;
    }
    
    /**
     * Verifica código de backup (2FA)
     */
    private function verificarCodigoBackup(Usuario2 $usuario, string $codigo): bool
    {
        $backupCodes = $usuario->getMfaBackupCodes();
        
        if (!$backupCodes || !is_array($backupCodes)) {
            return false;
        }
        
        // Verifica se o código está na lista
        $codigoHash = hash('sha256', $codigo);
        $index = array_search($codigoHash, $backupCodes);
        
        if ($index === false) {
            return false;
        }
        
        // Remove o código usado
        unset($backupCodes[$index]);
        $backupCodes = array_values($backupCodes);
        
        // Atualiza no banco
        $sql = "UPDATE usuarios2 SET mfa_backup_codes = :codes WHERE uuid = :uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'codes' => json_encode($backupCodes),
            'uuid' => $usuario->getUuid(),
        ]);
        
        return true;
    }
}
