<?php

namespace Src\Modules\Usuarios2\Entities;

use DateTime;
use JsonSerializable;

/**
 * Entity: Usuario2
 * 
 * Representa um usuário no sistema com recursos avançados de segurança
 */
class Usuario2 implements JsonSerializable
{
    // ═══════════════════════════════════════════════════════════
    // Propriedades
    // ═══════════════════════════════════════════════════════════
    
    private ?string $uuid = null;
    private string $nomeCompleto;
    private string $username;
    private string $email;
    private string $senhaHash;
    private ?DateTime $senhaAlteradaEm = null;
    private ?DateTime $senhaExpiraEm = null;
    private bool $requerTrocaSenha = false;
    
    // Perfil
    private ?string $urlAvatar = null;
    private ?string $urlCapa = null;
    private ?string $biografia = null;
    private ?string $telefone = null;
    private ?DateTime $dataNascimento = null;
    
    // OAuth2
    private ?string $googleId = null;
    private ?string $facebookId = null;
    private ?string $githubId = null;
    private ?string $oauthProvider = null;
    private ?string $oauthAvatar = null;
    
    // Permissões e Status
    private string $nivelAcesso = 'usuario';
    private bool $ativo = true;
    private bool $bloqueado = false;
    private ?DateTime $bloqueadoEm = null;
    private ?string $bloqueadoMotivo = null;
    private ?DateTime $bloqueadoAte = null;
    
    // Verificação de Email
    private bool $emailVerificado = false;
    private ?DateTime $emailVerificadoEm = null;
    private ?string $tokenVerificacaoEmail = null;
    private ?DateTime $tokenVerificacaoExpira = null;
    
    // Recuperação de Senha
    private ?string $tokenRecuperacaoSenha = null;
    private ?DateTime $tokenRecuperacaoExpira = null;
    private bool $tokenRecuperacaoUsado = false;
    
    // Autenticação Multifator (2FA)
    private bool $mfaHabilitado = false;
    private ?string $mfaSecret = null;
    private ?array $mfaBackupCodes = null;
    private ?DateTime $mfaHabilitadoEm = null;
    
    // Segurança e Tentativas
    private int $tentativasLogin = 0;
    private ?DateTime $ultimoLoginFalho = null;
    private ?DateTime $bloqueioTemporarioAte = null;
    
    // Último Acesso
    private ?DateTime $ultimoLogin = null;
    private ?string $ultimoIp = null;
    private ?string $ultimoUserAgent = null;
    
    // Preferências e Metadados
    private ?array $preferencias = null;
    private ?array $metadata = null;
    
    // Auditoria
    private ?DateTime $criadoEm = null;
    private ?string $criadoPor = null;
    private ?DateTime $atualizadoEm = null;
    private ?string $atualizadoPor = null;
    private ?DateTime $deletadoEm = null;
    private ?string $deletadoPor = null;
    
    // Relacionamentos (carregados sob demanda)
    private ?array $roles = null;
    private ?array $permissions = null;
    private ?array $sessoes = null;
    
    // ═══════════════════════════════════════════════════════════
    // Construtor
    // ═══════════════════════════════════════════════════════════
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->hydrate($data);
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // Hydration (popular a partir de array)
    // ═══════════════════════════════════════════════════════════
    
    public function hydrate(array $data): self
    {
        foreach ($data as $key => $value) {
            $method = 'set' . str_replace('_', '', ucwords($key, '_'));
            if (method_exists($this, $method)) {
                $this->$method($value);
            }
        }
        return $this;
    }
    
    // ═══════════════════════════════════════════════════════════
    // Getters e Setters
    // ═══════════════════════════════════════════════════════════
    
    public function getUuid(): ?string { return $this->uuid; }
    public function setUuid(?string $uuid): self { $this->uuid = $uuid; return $this; }
    
    public function getNomeCompleto(): string { return $this->nomeCompleto; }
    public function setNomeCompleto(string $nomeCompleto): self { $this->nomeCompleto = $nomeCompleto; return $this; }
    
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): self { $this->username = strtolower(trim($username)); return $this; }
    
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = strtolower(trim($email)); return $this; }
    
    public function getSenhaHash(): string { return $this->senhaHash; }
    public function setSenhaHash(string $senhaHash): self { $this->senhaHash = $senhaHash; return $this; }
    
    public function getSenhaAlteradaEm(): ?DateTime { return $this->senhaAlteradaEm; }
    public function setSenhaAlteradaEm($senhaAlteradaEm): self {
        $this->senhaAlteradaEm = $this->parseDateTime($senhaAlteradaEm);
        return $this;
    }
    
    public function getSenhaExpiraEm(): ?DateTime { return $this->senhaExpiraEm; }
    public function setSenhaExpiraEm($senhaExpiraEm): self {
        $this->senhaExpiraEm = $this->parseDateTime($senhaExpiraEm);
        return $this;
    }
    
    public function getRequerTrocaSenha(): bool { return $this->requerTrocaSenha; }
    public function setRequerTrocaSenha($requerTrocaSenha): self {
        $this->requerTrocaSenha = (bool) $requerTrocaSenha;
        return $this;
    }
    
    public function getUrlAvatar(): ?string { return $this->urlAvatar; }
    public function setUrlAvatar(?string $urlAvatar): self { $this->urlAvatar = $urlAvatar; return $this; }
    
    public function getUrlCapa(): ?string { return $this->urlCapa; }
    public function setUrlCapa(?string $urlCapa): self { $this->urlCapa = $urlCapa; return $this; }
    
    public function getBiografia(): ?string { return $this->biografia; }
    public function setBiografia(?string $biografia): self { $this->biografia = $biografia; return $this; }
    
    public function getTelefone(): ?string { return $this->telefone; }
    public function setTelefone(?string $telefone): self { $this->telefone = $telefone; return $this; }
    
    public function getDataNascimento(): ?DateTime { return $this->dataNascimento; }
    public function setDataNascimento($dataNascimento): self {
        $this->dataNascimento = $this->parseDateTime($dataNascimento);
        return $this;
    }
    
    public function getNivelAcesso(): string { return $this->nivelAcesso; }
    public function setNivelAcesso(string $nivelAcesso): self { $this->nivelAcesso = $nivelAcesso; return $this; }
    
    public function isAtivo(): bool { return $this->ativo; }
    public function setAtivo($ativo): self { $this->ativo = (bool) $ativo; return $this; }
    
    public function isBloqueado(): bool { return $this->bloqueado; }
    public function setBloqueado($bloqueado): self { $this->bloqueado = (bool) $bloqueado; return $this; }
    
    public function getBloqueadoEm(): ?DateTime { return $this->bloqueadoEm; }
    public function setBloqueadoEm($bloqueadoEm): self {
        $this->bloqueadoEm = $this->parseDateTime($bloqueadoEm);
        return $this;
    }
    
    public function getBloqueadoMotivo(): ?string { return $this->bloqueadoMotivo; }
    public function setBloqueadoMotivo(?string $bloqueadoMotivo): self { $this->bloqueadoMotivo = $bloqueadoMotivo; return $this; }
    
    public function getBloqueadoAte(): ?DateTime { return $this->bloqueadoAte; }
    public function setBloqueadoAte($bloqueadoAte): self {
        $this->bloqueadoAte = $this->parseDateTime($bloqueadoAte);
        return $this;
    }
    
    public function isEmailVerificado(): bool { return $this->emailVerificado; }
    public function setEmailVerificado($emailVerificado): self {
        $this->emailVerificado = (bool) $emailVerificado;
        return $this;
    }
    
    public function getEmailVerificadoEm(): ?DateTime { return $this->emailVerificadoEm; }
    public function setEmailVerificadoEm($emailVerificadoEm): self {
        $this->emailVerificadoEm = $this->parseDateTime($emailVerificadoEm);
        return $this;
    }
    
    public function getTokenVerificacaoEmail(): ?string { return $this->tokenVerificacaoEmail; }
    public function setTokenVerificacaoEmail(?string $tokenVerificacaoEmail): self {
        $this->tokenVerificacaoEmail = $tokenVerificacaoEmail;
        return $this;
    }
    
    public function getTokenVerificacaoExpira(): ?DateTime { return $this->tokenVerificacaoExpira; }
    public function setTokenVerificacaoExpira($tokenVerificacaoExpira): self {
        $this->tokenVerificacaoExpira = $this->parseDateTime($tokenVerificacaoExpira);
        return $this;
    }
    
    public function getTokenRecuperacaoSenha(): ?string { return $this->tokenRecuperacaoSenha; }
    public function setTokenRecuperacaoSenha(?string $tokenRecuperacaoSenha): self {
        $this->tokenRecuperacaoSenha = $tokenRecuperacaoSenha;
        return $this;
    }
    
    public function getTokenRecuperacaoExpira(): ?DateTime { return $this->tokenRecuperacaoExpira; }
    public function setTokenRecuperacaoExpira($tokenRecuperacaoExpira): self {
        $this->tokenRecuperacaoExpira = $this->parseDateTime($tokenRecuperacaoExpira);
        return $this;
    }
    
    public function isTokenRecuperacaoUsado(): bool { return $this->tokenRecuperacaoUsado; }
    public function setTokenRecuperacaoUsado($tokenRecuperacaoUsado): self {
        $this->tokenRecuperacaoUsado = (bool) $tokenRecuperacaoUsado;
        return $this;
    }
    
    public function isMfaHabilitado(): bool { return $this->mfaHabilitado; }
    public function setMfaHabilitado($mfaHabilitado): self {
        $this->mfaHabilitado = (bool) $mfaHabilitado;
        return $this;
    }
    
    public function getMfaSecret(): ?string { return $this->mfaSecret; }
    public function setMfaSecret(?string $mfaSecret): self { $this->mfaSecret = $mfaSecret; return $this; }
    
    public function getMfaBackupCodes(): ?array { return $this->mfaBackupCodes; }
    public function setMfaBackupCodes($mfaBackupCodes): self {
        if (is_string($mfaBackupCodes)) {
            $this->mfaBackupCodes = json_decode($mfaBackupCodes, true);
        } else {
            $this->mfaBackupCodes = $mfaBackupCodes;
        }
        return $this;
    }
    
    public function getMfaHabilitadoEm(): ?DateTime { return $this->mfaHabilitadoEm; }
    public function setMfaHabilitadoEm($mfaHabilitadoEm): self {
        $this->mfaHabilitadoEm = $this->parseDateTime($mfaHabilitadoEm);
        return $this;
    }
    
    public function getTentativasLogin(): int { return $this->tentativasLogin; }
    public function setTentativasLogin($tentativasLogin): self {
        $this->tentativasLogin = (int) $tentativasLogin;
        return $this;
    }
    
    public function getUltimoLoginFalho(): ?DateTime { return $this->ultimoLoginFalho; }
    public function setUltimoLoginFalho($ultimoLoginFalho): self {
        $this->ultimoLoginFalho = $this->parseDateTime($ultimoLoginFalho);
        return $this;
    }
    
    public function getBloqueioTemporarioAte(): ?DateTime { return $this->bloqueioTemporarioAte; }
    public function setBloqueioTemporarioAte($bloqueioTemporarioAte): self {
        $this->bloqueioTemporarioAte = $this->parseDateTime($bloqueioTemporarioAte);
        return $this;
    }
    
    public function getUltimoLogin(): ?DateTime { return $this->ultimoLogin; }
    public function setUltimoLogin($ultimoLogin): self {
        $this->ultimoLogin = $this->parseDateTime($ultimoLogin);
        return $this;
    }
    
    public function getUltimoIp(): ?string { return $this->ultimoIp; }
    public function setUltimoIp(?string $ultimoIp): self { $this->ultimoIp = $ultimoIp; return $this; }
    
    public function getUltimoUserAgent(): ?string { return $this->ultimoUserAgent; }
    public function setUltimoUserAgent(?string $ultimoUserAgent): self {
        $this->ultimoUserAgent = $ultimoUserAgent;
        return $this;
    }
    
    public function getPreferencias(): ?array { return $this->preferencias; }
    public function setPreferencias($preferencias): self {
        if (is_string($preferencias)) {
            $this->preferencias = json_decode($preferencias, true);
        } else {
            $this->preferencias = $preferencias;
        }
        return $this;
    }
    
    // OAuth2 Getters/Setters
    public function getGoogleId(): ?string { return $this->googleId; }
    public function setGoogleId(?string $googleId): self { $this->googleId = $googleId; return $this; }
    
    public function getFacebookId(): ?string { return $this->facebookId; }
    public function setFacebookId(?string $facebookId): self { $this->facebookId = $facebookId; return $this; }
    
    public function getGithubId(): ?string { return $this->githubId; }
    public function setGithubId(?string $githubId): self { $this->githubId = $githubId; return $this; }
    
    public function getOauthProvider(): ?string { return $this->oauthProvider; }
    public function setOauthProvider(?string $oauthProvider): self { $this->oauthProvider = $oauthProvider; return $this; }
    
    public function getOauthAvatar(): ?string { return $this->oauthAvatar; }
    public function setOauthAvatar(?string $oauthAvatar): self { $this->oauthAvatar = $oauthAvatar; return $this; }
    
    // Helper method to get avatar (prioritizes OAuth avatar)
    public function getAvatar(): ?string {
        return $this->oauthAvatar ?? $this->urlAvatar;
    }
    
    public function setAvatar(?string $avatar): self {
        if ($this->oauthProvider) {
            $this->oauthAvatar = $avatar;
        } else {
            $this->urlAvatar = $avatar;
        }
        return $this;
    }
    
    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata($metadata): self {
        if (is_string($metadata)) {
            $this->metadata = json_decode($metadata, true);
        } else {
            $this->metadata = $metadata;
        }
        return $this;
    }
    
    public function getCriadoEm(): ?DateTime { return $this->criadoEm; }
    public function setCriadoEm($criadoEm): self {
        $this->criadoEm = $this->parseDateTime($criadoEm);
        return $this;
    }
    
    public function getCriadoPor(): ?string { return $this->criadoPor; }
    public function setCriadoPor(?string $criadoPor): self { $this->criadoPor = $criadoPor; return $this; }
    
    public function getAtualizadoEm(): ?DateTime { return $this->atualizadoEm; }
    public function setAtualizadoEm($atualizadoEm): self {
        $this->atualizadoEm = $this->parseDateTime($atualizadoEm);
        return $this;
    }
    
    public function getAtualizadoPor(): ?string { return $this->atualizadoPor; }
    public function setAtualizadoPor(?string $atualizadoPor): self {
        $this->atualizadoPor = $atualizadoPor;
        return $this;
    }
    
    public function getDeletadoEm(): ?DateTime { return $this->deletadoEm; }
    public function setDeletadoEm($deletadoEm): self {
        $this->deletadoEm = $this->parseDateTime($deletadoEm);
        return $this;
    }
    
    public function getDeletadoPor(): ?string { return $this->deletadoPor; }
    public function setDeletadoPor(?string $deletadoPor): self {
        $this->deletadoPor = $deletadoPor;
        return $this;
    }
    
    // Relacionamentos
    public function getRoles(): ?array { return $this->roles; }
    public function setRoles(?array $roles): self { $this->roles = $roles; return $this; }
    
    public function getPermissions(): ?array { return $this->permissions; }
    public function setPermissions(?array $permissions): self { $this->permissions = $permissions; return $this; }
    
    public function getSessoes(): ?array { return $this->sessoes; }
    public function setSessoes(?array $sessoes): self { $this->sessoes = $sessoes; return $this; }
    
    // ═══════════════════════════════════════════════════════════
    // Métodos Auxiliares
    // ═══════════════════════════════════════════════════════════
    
    /**
     * Converte string/timestamp para DateTime
     */
    private function parseDateTime($value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        if ($value instanceof DateTime) {
            return $value;
        }
        
        try {
            return new DateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Verifica se o usuário está temporariamente bloqueado
     */
    public function isTemporariamenteBloqueado(): bool
    {
        if ($this->bloqueioTemporarioAte === null) {
            return false;
        }
        
        return $this->bloqueioTemporarioAte > new DateTime();
    }
    
    /**
     * Verifica se a senha expirou
     */
    public function isSenhaExpirada(): bool
    {
        if ($this->senhaExpiraEm === null) {
            return false;
        }
        
        return $this->senhaExpiraEm < new DateTime();
    }
    
    /**
     * Verifica se o token de verificação de email é válido
     */
    public function isTokenVerificacaoValido(): bool
    {
        if ($this->tokenVerificacaoExpira === null) {
            return false;
        }
        
        return $this->tokenVerificacaoExpira > new DateTime();
    }
    
    /**
     * Verifica se o token de recuperação de senha é válido
     */
    public function isTokenRecuperacaoValido(): bool
    {
        if ($this->tokenRecuperacaoExpira === null || $this->tokenRecuperacaoUsado) {
            return false;
        }
        
        return $this->tokenRecuperacaoExpira > new DateTime();
    }
    
    /**
     * Verifica se o usuário pode fazer login
     */
    public function podeLogar(): array
    {
        if (!$this->ativo) {
            return ['pode' => false, 'motivo' => 'Usuário inativo'];
        }
        
        if ($this->bloqueado) {
            return ['pode' => false, 'motivo' => 'Usuário bloqueado: ' . ($this->bloqueadoMotivo ?? 'Sem motivo especificado')];
        }
        
        if ($this->isTemporariamenteBloqueado()) {
            return ['pode' => false, 'motivo' => 'Usuário temporariamente bloqueado por tentativas excessivas'];
        }
        
        if ($this->isSenhaExpirada()) {
            return ['pode' => false, 'motivo' => 'Senha expirada. Solicite uma nova senha'];
        }
        
        return ['pode' => true];
    }
    
    /**
     * Verifica se o usuário tem uma permissão específica
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->permissions === null) {
            return false;
        }
        
        foreach ($this->permissions as $perm) {
            if ($perm['slug'] === $permissionSlug) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verifica se o usuário tem uma role específica
     */
    public function hasRole(string $roleSlug): bool
    {
        if ($this->roles === null) {
            return false;
        }
        
        foreach ($this->roles as $role) {
            if ($role['slug'] === $roleSlug) {
                return true;
            }
        }
        
        return false;
    }
    
    // ═══════════════════════════════════════════════════════════
    // JSON Serialization
    // ═══════════════════════════════════════════════════════════
    
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    /**
     * Converte a entidade para array (sem dados sensíveis)
     */
    public function toArray(bool $incluirSensiveis = false): array
    {
        $data = [
            'uuid' => $this->uuid,
            'nome_completo' => $this->nomeCompleto,
            'username' => $this->username,
            'email' => $this->email,
            'url_avatar' => $this->urlAvatar,
            'url_capa' => $this->urlCapa,
            'biografia' => $this->biografia,
            'telefone' => $this->telefone,
            'data_nascimento' => $this->dataNascimento?->format('Y-m-d'),
            'nivel_acesso' => $this->nivelAcesso,
            'ativo' => $this->ativo,
            'bloqueado' => $this->bloqueado,
            'email_verificado' => $this->emailVerificado,
            'email_verificado_em' => $this->emailVerificadoEm?->format('Y-m-d H:i:s'),
            'mfa_habilitado' => $this->mfaHabilitado,
            'ultimo_login' => $this->ultimoLogin?->format('Y-m-d H:i:s'),
            'preferencias' => $this->preferencias,
            'criado_em' => $this->criadoEm?->format('Y-m-d H:i:s'),
            'atualizado_em' => $this->atualizadoEm?->format('Y-m-d H:i:s'),
        ];
        
        if ($this->roles !== null) {
            $data['roles'] = $this->roles;
        }
        
        if ($this->permissions !== null) {
            $data['permissions'] = $this->permissions;
        }
        
        if ($incluirSensiveis) {
            $data['senha_hash'] = $this->senhaHash;
            $data['mfa_secret'] = $this->mfaSecret;
            $data['mfa_backup_codes'] = $this->mfaBackupCodes;
            $data['token_verificacao_email'] = $this->tokenVerificacaoEmail;
            $data['token_recuperacao_senha'] = $this->tokenRecuperacaoSenha;
        }
        
        return $data;
    }
}
