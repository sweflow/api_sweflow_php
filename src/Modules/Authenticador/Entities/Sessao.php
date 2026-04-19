<?php

namespace Src\Modules\Authenticador\Entities;

use DateTime;

/**
 * Entity: Sessao
 * 
 * Representa uma sessão de usuário autenticado
 */
class Sessao
{
    private ?int $id = null;
    private ?string $uuid = null;
    private string $usuarioUuid;
    private ?string $tokenHash = null;
    private ?string $refreshTokenHash = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    private ?string $dispositivoTipo = null;
    private ?string $dispositivoNome = null;
    private ?string $navegador = null;
    private ?string $sistemaOperacional = null;
    private bool $ativa = true;
    private DateTime $expiraEm;
    private DateTime $criadaEm;
    private ?DateTime $ultimoUso = null;
    private ?DateTime $revogadaEm = null;
    private ?string $revogadaPor = null;
    private ?string $revogadaMotivo = null;
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->hydrate($data);
        }
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUuid(): ?string { return $this->uuid; }
    public function getUsuarioUuid(): string { return $this->usuarioUuid; }
    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function getRefreshTokenHash(): ?string { return $this->refreshTokenHash; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getDispositivoTipo(): ?string { return $this->dispositivoTipo; }
    public function getDispositivoNome(): ?string { return $this->dispositivoNome; }
    public function getNavegador(): ?string { return $this->navegador; }
    public function getSistemaOperacional(): ?string { return $this->sistemaOperacional; }
    public function isAtiva(): bool { return $this->ativa; }
    public function getExpiraEm(): DateTime { return $this->expiraEm; }
    public function getCriadaEm(): DateTime { return $this->criadaEm; }
    public function getUltimoUso(): ?DateTime { return $this->ultimoUso; }
    public function getRevogadaEm(): ?DateTime { return $this->revogadaEm; }
    public function getRevogadaPor(): ?string { return $this->revogadaPor; }
    public function getRevogadaMotivo(): ?string { return $this->revogadaMotivo; }
    
    // Setters
    public function setId(?int $id): self { $this->id = $id; return $this; }
    public function setUuid(?string $uuid): self { $this->uuid = $uuid; return $this; }
    public function setUsuarioUuid(string $usuarioUuid): self { $this->usuarioUuid = $usuarioUuid; return $this; }
    public function setTokenHash(?string $tokenHash): self { $this->tokenHash = $tokenHash; return $this; }
    public function setRefreshTokenHash(?string $refreshTokenHash): self { $this->refreshTokenHash = $refreshTokenHash; return $this; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }
    public function setDispositivoTipo(?string $dispositivoTipo): self { $this->dispositivoTipo = $dispositivoTipo; return $this; }
    public function setDispositivoNome(?string $dispositivoNome): self { $this->dispositivoNome = $dispositivoNome; return $this; }
    public function setNavegador(?string $navegador): self { $this->navegador = $navegador; return $this; }
    public function setSistemaOperacional(?string $sistemaOperacional): self { $this->sistemaOperacional = $sistemaOperacional; return $this; }
    public function setAtiva(bool $ativa): self { $this->ativa = $ativa; return $this; }
    public function setExpiraEm(DateTime $expiraEm): self { $this->expiraEm = $expiraEm; return $this; }
    public function setCriadaEm(DateTime $criadaEm): self { $this->criadaEm = $criadaEm; return $this; }
    public function setUltimoUso(?DateTime $ultimoUso): self { $this->ultimoUso = $ultimoUso; return $this; }
    public function setRevogadaEm(?DateTime $revogadaEm): self { $this->revogadaEm = $revogadaEm; return $this; }
    public function setRevogadaPor(?string $revogadaPor): self { $this->revogadaPor = $revogadaPor; return $this; }
    public function setRevogadaMotivo(?string $revogadaMotivo): self { $this->revogadaMotivo = $revogadaMotivo; return $this; }
    
    /**
     * Verifica se a sessão está expirada
     */
    public function isExpirada(): bool
    {
        return $this->expiraEm < new DateTime();
    }
    
    /**
     * Verifica se a sessão é válida (ativa e não expirada)
     */
    public function isValida(): bool
    {
        return $this->ativa && !$this->isExpirada();
    }
    
    /**
     * Hydrate entity from array
     */
    private function hydrate(array $data): void
    {
        if (isset($data['id'])) $this->id = (int)$data['id'];
        if (isset($data['uuid'])) $this->uuid = $data['uuid'];
        if (isset($data['usuario_uuid'])) $this->usuarioUuid = $data['usuario_uuid'];
        if (isset($data['token_hash'])) $this->tokenHash = $data['token_hash'];
        if (isset($data['refresh_token_hash'])) $this->refreshTokenHash = $data['refresh_token_hash'];
        if (isset($data['ip_address'])) $this->ipAddress = $data['ip_address'];
        if (isset($data['user_agent'])) $this->userAgent = $data['user_agent'];
        if (isset($data['dispositivo_tipo'])) $this->dispositivoTipo = $data['dispositivo_tipo'];
        if (isset($data['dispositivo_nome'])) $this->dispositivoNome = $data['dispositivo_nome'];
        if (isset($data['navegador'])) $this->navegador = $data['navegador'];
        if (isset($data['sistema_operacional'])) $this->sistemaOperacional = $data['sistema_operacional'];
        if (isset($data['ativa'])) $this->ativa = (bool)$data['ativa'];
        if (isset($data['expira_em'])) $this->expiraEm = new DateTime($data['expira_em']);
        if (isset($data['criada_em'])) $this->criadaEm = new DateTime($data['criada_em']);
        if (isset($data['ultimo_uso']) && $data['ultimo_uso']) $this->ultimoUso = new DateTime($data['ultimo_uso']);
        if (isset($data['revogada_em']) && $data['revogada_em']) $this->revogadaEm = new DateTime($data['revogada_em']);
        if (isset($data['revogada_por'])) $this->revogadaPor = $data['revogada_por'];
        if (isset($data['revogada_motivo'])) $this->revogadaMotivo = $data['revogada_motivo'];
    }
    
    /**
     * Converte para array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'usuario_uuid' => $this->usuarioUuid,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'dispositivo_tipo' => $this->dispositivoTipo,
            'dispositivo_nome' => $this->dispositivoNome,
            'navegador' => $this->navegador,
            'sistema_operacional' => $this->sistemaOperacional,
            'ativa' => $this->ativa,
            'expira_em' => $this->expiraEm->format('Y-m-d H:i:s'),
            'criada_em' => $this->criadaEm->format('Y-m-d H:i:s'),
            'ultimo_uso' => $this->ultimoUso?->format('Y-m-d H:i:s'),
            'revogada_em' => $this->revogadaEm?->format('Y-m-d H:i:s'),
            'revogada_por' => $this->revogadaPor,
            'revogada_motivo' => $this->revogadaMotivo,
            'is_valida' => $this->isValida(),
            'is_expirada' => $this->isExpirada(),
        ];
    }
}
