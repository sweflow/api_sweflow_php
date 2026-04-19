<?php

namespace Src\Modules\Usuario\Entities;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Src\Kernel\Contracts\AuthenticatableInterface;
use Src\Kernel\Utils\RelogioTimeZone;
use Src\Modules\Usuario\Exceptions\InvalidEmailException;
use Src\Modules\Usuario\Exceptions\InvalidPasswordException;
use Src\Modules\Usuario\Exceptions\InvalidUsernameException;

final class Usuario implements AuthenticatableInterface
{
    private const NIVEIS_VALIDOS = ['usuario', 'admin', 'moderador', 'admin_system'];

    private function __construct(
        private readonly UuidInterface    $uuid,
        private string                    $nomeCompleto,
        private string                    $username,
        private string                    $email,
        private string                    $senhaHash,
        private ?string                   $urlAvatar,
        private ?string                   $urlCapa,
        private ?string                   $biografia,
        private string                    $nivelAcesso,
        private ?string                   $tokenRecuperacaoSenha,
        private ?string                   $tokenVerificacaoEmail,
        private bool                      $ativo,
        private bool                      $verificadoEmail,
        private readonly DateTimeImmutable $criadoEm,
        private ?DateTimeImmutable        $atualizadoEm,
        private string                    $statusVerificacao,
        private ?DateTimeImmutable        $senhaAlteradaEm = null,
    ) {}

    // ── Factory methods ───────────────────────────────────────────────────

    public static function registrar(
        string  $nomeCompleto,
        string  $username,
        string  $email,
        string  $senha,
        ?string $urlAvatar = null,
        ?string $urlCapa = null,
        ?string $biografia = null,
        string  $nivelAcesso = 'usuario',
        bool    $verificadoEmail = false,
        string  $statusVerificacao = 'Não verificado'
    ): self {
        $usernameNormalizado = self::normalizarUsername($username);

        self::validarUsername($usernameNormalizado);
        self::validarEmail($email);
        self::validarSenha($senha);
        self::validarNivelAcesso($nivelAcesso);

        return new self(
            uuid:                  Uuid::uuid4(),
            nomeCompleto:          $nomeCompleto,
            username:              $usernameNormalizado,
            email:                 $email,
            senhaHash:             password_hash($senha, PASSWORD_ARGON2ID),
            urlAvatar:             $urlAvatar,
            urlCapa:               $urlCapa,
            biografia:             $biografia,
            nivelAcesso:           $nivelAcesso,
            tokenRecuperacaoSenha: null,
            tokenVerificacaoEmail: null,
            ativo:                 true,
            verificadoEmail:       $verificadoEmail,
            criadoEm:              RelogioTimeZone::agora(),
            atualizadoEm:          null,
            statusVerificacao:     $statusVerificacao,
        );
    }

    public static function reconstituir(
        UuidInterface      $uuid,
        string             $nomeCompleto,
        string             $username,
        string             $email,
        string             $senhaHash,
        string             $nivelAcesso,
        bool               $ativo,
        bool               $verificadoEmail,
        DateTimeImmutable  $criadoEm,
        ?string            $urlAvatar = null,
        ?string            $urlCapa = null,
        ?string            $biografia = null,
        ?string            $tokenRecuperacaoSenha = null,
        ?string            $tokenVerificacaoEmail = null,
        ?DateTimeImmutable $atualizadoEm = null,
        string             $statusVerificacao = 'Não verificado',
        ?DateTimeImmutable $senhaAlteradaEm = null,
    ): self {
        return new self(
            uuid:                  $uuid,
            nomeCompleto:          $nomeCompleto,
            username:              self::normalizarUsername($username),
            email:                 $email,
            senhaHash:             $senhaHash,
            urlAvatar:             $urlAvatar,
            urlCapa:               $urlCapa,
            biografia:             $biografia,
            nivelAcesso:           $nivelAcesso,
            tokenRecuperacaoSenha: $tokenRecuperacaoSenha,
            tokenVerificacaoEmail: $tokenVerificacaoEmail,
            ativo:                 $ativo,
            verificadoEmail:       $verificadoEmail,
            criadoEm:              $criadoEm,
            atualizadoEm:          $atualizadoEm,
            statusVerificacao:     $statusVerificacao,
            senhaAlteradaEm:       $senhaAlteradaEm,
        );
    }

    // ── AuthenticatableInterface ──────────────────────────────────────────
    // Permite que o módulo Auth funcione com esta entidade sem acoplamento direto.

    public function getAuthId(): string       { return $this->uuid->toString(); }
    public function getAuthEmail(): string    { return $this->email; }
    /** @phpstan-ignore return.unusedType */
    public function getAuthUsername(): ?string { return $this->username; }
    public function getAuthRole(): string     { return $this->nivelAcesso; }

    // ── Getters ───────────────────────────────────────────────────────────

    public function getUuid(): UuidInterface            { return $this->uuid; }
    public function getNomeCompleto(): string           { return $this->nomeCompleto; }
    public function getUsername(): string               { return $this->username; }
    public function getEmail(): string                  { return $this->email; }
    public function getSenhaHash(): string              { return $this->senhaHash; }
    public function getUrlAvatar(): ?string             { return $this->urlAvatar; }
    public function getUrlCapa(): ?string               { return $this->urlCapa; }
    public function getBiografia(): ?string             { return $this->biografia; }
    public function getNivelAcesso(): string            { return $this->nivelAcesso; }
    public function isAtivo(): bool                     { return $this->ativo; }
    public function isEmailVerificado(): bool           { return $this->verificadoEmail; }
    public function getCriadoEm(): DateTimeImmutable    { return $this->criadoEm; }
    public function getAtualizadoEm(): ?DateTimeImmutable { return $this->atualizadoEm; }
    public function getSenhaAlteradaEm(): ?int          { return $this->senhaAlteradaEm?->getTimestamp(); }
    public function getTokenRecuperacaoSenha(): ?string { return $this->tokenRecuperacaoSenha; }
    public function getTokenVerificacaoEmail(): ?string { return $this->tokenVerificacaoEmail; }
    public function getStatusVerificacao(): string      { return $this->statusVerificacao; }

    // ── Métodos de domínio (com validação interna) ────────────────────────

    public function alterarNomeCompleto(string $nomeCompleto): void
    {
        $nome = trim($nomeCompleto);
        if ($nome === '') {
            throw new \InvalidArgumentException('Nome completo não pode ser vazio.');
        }
        $this->nomeCompleto  = $nome;
        $this->atualizadoEm  = RelogioTimeZone::agora();
    }

    public function alterarUsername(string $username): void
    {
        $normalizado = self::normalizarUsername($username);
        self::validarUsername($normalizado);
        $this->username     = $normalizado;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    /** Alias de alterarUsername() — mantido para compatibilidade com testes e código legado. */
    public function setUsername(string $username): void
    {
        $this->alterarUsername($username);
    }

    public function alterarEmail(string $email): void
    {
        self::validarEmail($email);
        $this->email        = $email;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    /** Alias de alterarEmail() — mantido para compatibilidade com testes e código legado. */
    public function setEmail(string $email): void
    {
        $this->alterarEmail($email);
    }

    public function alterarSenha(string $senhaPlana): void
    {
        self::validarSenha($senhaPlana);
        $this->senhaHash       = password_hash($senhaPlana, PASSWORD_ARGON2ID);
        $this->senhaAlteradaEm = RelogioTimeZone::agora();
        $this->atualizadoEm    = $this->senhaAlteradaEm;
    }

    public function alterarAvatar(?string $urlAvatar): void
    {
        $this->urlAvatar    = $urlAvatar;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    public function alterarCapa(?string $urlCapa): void
    {
        $this->urlCapa      = $urlCapa;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    public function alterarBiografia(?string $biografia): void
    {
        $this->biografia    = $biografia;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    public function promoverPara(string $nivelAcesso): void
    {
        self::validarNivelAcesso($nivelAcesso);
        $this->nivelAcesso  = $nivelAcesso;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    public function ativar(): void
    {
        $this->ativo        = true;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    public function desativar(): void
    {
        $this->ativo        = false;
        $this->atualizadoEm = RelogioTimeZone::agora();
    }

    public function verificarEmail(): void
    {
        $this->verificadoEmail      = true;
        $this->tokenVerificacaoEmail = null;
        $this->statusVerificacao    = 'Verificado';
        $this->atualizadoEm         = RelogioTimeZone::agora();
    }

    public function revogarVerificacaoEmail(): void
    {
        $this->verificadoEmail   = false;
        $this->statusVerificacao = 'Não verificado';
        $this->atualizadoEm      = RelogioTimeZone::agora();
    }

    public function gerarTokenRecuperacaoSenha(string $token): void
    {
        $this->tokenRecuperacaoSenha = $token;
        $this->atualizadoEm          = RelogioTimeZone::agora();
    }

    public function gerarTokenVerificacaoEmail(string $token): void
    {
        $this->tokenVerificacaoEmail = $token;
        $this->atualizadoEm          = RelogioTimeZone::agora();
    }

    // ── Verificação de senha ──────────────────────────────────────────────

    public function verificarSenha(string $senhaPlana): bool
    {
        return password_verify($senhaPlana, $this->senhaHash);
    }

    // ── Representação ─────────────────────────────────────────────────────

    /**
     * Omite e-mail e UUID para evitar vazamento de PII em logs.
     */
    public function __toString(): string
    {
        $username    = $this->getUsername();
        $nivelAcesso = $this->getNivelAcesso();
        $ativo       = $this->isAtivo() ? 'Sim' : 'Não';
        $criadoEm    = $this->getCriadoEm()->format('d-m-Y H:i:s');
        return "Usuário [Username: {$username}, Nível: {$nivelAcesso}, Ativo: {$ativo}, Criado Em: {$criadoEm}]";
    }

    // ── Validações privadas ───────────────────────────────────────────────

    private static function normalizarUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    private static function validarNivelAcesso(string $nivel): void
    {
        if (!in_array($nivel, self::NIVEIS_VALIDOS, true)) {
            throw new \InvalidArgumentException('Nível de acesso inválido.');
        }
    }

    private static function validarEmail(string $email): void
    {
        if (trim($email) === '') {
            throw new InvalidEmailException('E-mail não informado.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidEmailException('Formato de e-mail inválido.');
        }
    }

    private static function validarUsername(string $username): void
    {
        if (trim($username) === '') {
            throw new InvalidUsernameException('Username não informado.');
        }
        if (preg_match('/^[._]/', $username)) {
            throw new InvalidUsernameException('Username não pode iniciar com caractere especial (ponto ou underline).');
        }
        // mb_strlen para contar caracteres, não bytes
        if (mb_strlen($username) < 3) {
            throw new InvalidUsernameException('Username deve ter ao menos 3 caracteres.');
        }
        if (!preg_match('/^[a-z0-9._]+$/', $username)) {
            throw new InvalidUsernameException('Username só pode conter letras minúsculas, números, ponto ou underline.');
        }
        $matches = preg_match_all('/[._]/', $username);
        if ($matches !== false && $matches > 1) {
            throw new InvalidUsernameException('Username pode conter apenas um caractere especial (ponto ou underline).');
        }
    }

    private static function validarSenha(string $senha): void
    {
        if (trim($senha) === '') {
            throw new InvalidPasswordException('Senha não informada.');
        }
        if (strlen($senha) < 8) {
            throw new InvalidPasswordException('Senha muito curta. Mínimo de 8 caracteres.');
        }
        if (!preg_match('/[A-Z]/', $senha)) {
            throw new InvalidPasswordException('Senha deve conter ao menos uma letra maiúscula.');
        }
        if (!preg_match('/[a-z]/', $senha)) {
            throw new InvalidPasswordException('Senha deve conter ao menos uma letra minúscula.');
        }
        if (!preg_match('/[0-9]/', $senha)) {
            throw new InvalidPasswordException('Senha deve conter ao menos um número.');
        }
        if (!preg_match('/[^a-zA-Z0-9]/', $senha)) {
            throw new InvalidPasswordException('Senha deve conter ao menos um caractere especial.');
        }
    }
}
