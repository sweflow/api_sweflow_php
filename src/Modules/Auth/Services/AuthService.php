<?php

namespace Src\Modules\Auth\Services;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Kernel\Contracts\AuthenticatableInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Modules\Auth\Repositories\RefreshTokenRepository;
use Ramsey\Uuid\Uuid;
use DateTimeImmutable;

/**
 * Serviço de autenticação desacoplado.
 *
 * Trabalha com qualquer entidade que implemente AuthenticatableInterface —
 * não depende da entidade Usuario nem de nenhum módulo específico.
 *
 * Para usar com uma entidade diferente de Usuario:
 *   1. Implemente AuthenticatableInterface na sua entidade
 *   2. Implemente UserRepositoryInterface no seu repository
 *   3. Registre no container: bind(UserRepositoryInterface::class, SeuRepository::class)
 */
class AuthService
{
    private string $jwtSecret;
    private string $jwtApiSecret;
    private string $emissor;
    private string $audiencia;
    private int    $accessTtl;
    private int    $refreshTtl;

    public function __construct(
        private UserRepositoryInterface $usuarios,
        private ?RefreshTokenRepository $refreshTokens = null
    ) {
        $this->jwtSecret    = $this->lerEnv('JWT_SECRET',    '');
        $this->jwtApiSecret = $this->lerEnv('JWT_API_SECRET', '');
        $this->emissor      = $this->lerEnv('JWT_ISSUER',    $this->lerEnv('APP_URL', ''));
        $this->audiencia    = $this->lerEnv('JWT_AUDIENCE',  $this->lerEnv('APP_URL_FRONTEND', ''));

        $ttlAccess  = (int) $this->lerEnv('JWT_EXPIRATION_TIME', '900');
        $ttlRefresh = (int) $this->lerEnv('REFRESH_TOKEN_EXPIRATION_SECONDS', '2592000');
        $this->accessTtl  = $ttlAccess  > 0 ? $ttlAccess  : 900;
        $this->refreshTtl = $ttlRefresh > 0 ? $ttlRefresh : 2592000;

        // Valida comprimento mínimo do JWT_SECRET (32 bytes = 64 hex chars ou 32 chars raw)
        if ($this->jwtSecret !== '' && strlen($this->jwtSecret) < 32) {
            throw new \RuntimeException('JWT_SECRET deve ter pelo menos 32 caracteres.', 500);
        }
        if ($this->jwtApiSecret !== '' && strlen($this->jwtApiSecret) < 32) {
            throw new \RuntimeException('JWT_API_SECRET deve ter pelo menos 32 caracteres.', 500);
        }
    }

    private function lerEnv(string $key, string $default): string
    {
        return trim((string) ($_ENV[$key] ?? getenv($key) ?: $default));
    }

    // ── Autenticação ──────────────────────────────────────────────────────

    /**
     * Autentica um usuário por login (e-mail ou username) e senha.
     * Retorna qualquer objeto que implemente AuthenticatableInterface.
     */
    public function autenticar(string $login, string $senha): AuthenticatableInterface
    {
        if (trim($login) === '' || trim($senha) === '') {
            throw new DomainException('Login e senha são obrigatórios.', 400);
        }

        $usuario = $this->buscarUsuario($login);
        if (!$usuario || !$usuario->verificarSenha($senha)) {
            throw new DomainException('Credenciais inválidas.', 401);
        }

        if (!$usuario->isAtivo()) {
            throw new DomainException('Usuário desativado.', 403);
        }

        return $usuario;
    }

    // ── Emissão de tokens ─────────────────────────────────────────────────

    /** Emite tokens assinados com JWT_SECRET (usuários comuns). */
    public function emitirTokens(AuthenticatableInterface $usuario): array
    {
        return $this->emitirTokensComSecret($usuario, $this->segredoJwt());
    }

    /** Emite tokens assinados com JWT_API_SECRET (admin_system). */
    public function emitirTokensAdmin(AuthenticatableInterface $usuario): array
    {
        return $this->emitirTokensComSecret($usuario, $this->segredoJwtAdmin());
    }

    private function emitirTokensComSecret(AuthenticatableInterface $usuario, string $secret): array
    {
        $agora = time();

        $role = $usuario->getAuthRole();

        $accessJti = Uuid::uuid4()->toString();
        $accessExp = $agora + $this->accessTtl;
        $accessPayload = [
            'sub'          => $usuario->getAuthId(),
            'email'        => $usuario->getAuthEmail(),
            'username'     => $usuario->getAuthUsername() ?? $usuario->getAuthEmail(),
            'nome_completo'=> method_exists($usuario, 'getNomeCompleto') ? $usuario->getNomeCompleto() : ($usuario->getAuthUsername() ?? ''),
            'nivel_acesso' => $usuario->getAuthRole(),
            'iat'          => $agora,
            'exp'          => $accessExp,
            'iss'          => $this->emissor,
            'aud'          => $this->audiencia,
            'tipo'         => 'user',
            'jti'          => $accessJti,
        ];
        $access = JWT::encode($accessPayload, $secret, 'HS256');

        $refreshJti = Uuid::uuid4()->toString();
        $refreshExp = $agora + $this->refreshTtl;
        $refreshPayload = [
            'sub'  => $usuario->getAuthId(),
            'iat'  => $agora,
            'exp'  => $refreshExp,
            'iss'  => $this->emissor,
            'aud'  => $this->audiencia,
            'tipo' => 'refresh',
            'jti'  => $refreshJti,
        ];
        $refresh = JWT::encode($refreshPayload, $secret, 'HS256');

        if ($this->refreshTokens) {
            $hash = hash_hmac('sha256', $refresh, $secret);
            $this->refreshTokens->store(
                $refreshJti,
                $usuario->getAuthId(),
                $hash,
                (new DateTimeImmutable())->setTimestamp($refreshExp)
            );
        }

        return [
            'access_token'      => $access,
            'access_expira_em'  => $accessExp,
            'refresh_token'     => $refresh,
            'refresh_expira_em' => $refreshExp,
        ];
    }

    // ── Decodificação de tokens ───────────────────────────────────────────

    public function decodificarToken(string $token): object
    {
        $apiSecret = $this->segredoJwtAdmin();
        try {
            $payload = JWT::decode($token, new Key($apiSecret, 'HS256'));
            if (isset($payload->tipo) && $payload->tipo === 'refresh') {
                throw new DomainException('Token de refresh não pode ser usado como access token.', 401);
            }
            return $payload;
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable) {}

        $payload = JWT::decode($token, new Key($this->segredoJwt(), 'HS256'));
        if (isset($payload->tipo) && $payload->tipo === 'refresh') {
            throw new DomainException('Token de refresh não pode ser usado como access token.', 401);
        }
        return $payload;
    }

    public function decodificarRefresh(string $token): object
    {
        [$payload] = $this->decodificarRefreshComSecret($token);
        return $payload;
    }

    /** @return array{0: object, 1: bool} [payload, assinadoComApiSecret] */
    public function decodificarRefreshComSecret(string $token): array
    {
        $apiSecret = $this->segredoJwtAdmin();
        try {
            $payload = JWT::decode($token, new Key($apiSecret, 'HS256'));
            if (!isset($payload->tipo) || $payload->tipo !== 'refresh') {
                throw new DomainException('Token de refresh inválido.', 401);
            }
            return [$payload, true];
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable) {}

        try {
            $payload = JWT::decode($token, new Key($this->segredoJwt(), 'HS256'));
            if (!isset($payload->tipo) || $payload->tipo !== 'refresh') {
                throw new DomainException('Token de refresh inválido.', 401);
            }
            return [$payload, false];
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable) {}

        throw new DomainException('Token de refresh inválido ou expirado.', 401);
    }

    // ── Refresh tokens ────────────────────────────────────────────────────

    public function validarRefreshNaoRevogado(object $payload, string $rawToken, bool $assinadoComApiSecret = false): void
    {
        if (!$this->refreshTokens) return;
        $jti = $payload->jti ?? '';
        if ($jti === '') throw new DomainException('Refresh sem jti.', 401);
        $row = $this->refreshTokens->findValidByJti($jti);
        if (!$row) throw new DomainException('Refresh revogado ou expirado.', 401);
        $secret = $assinadoComApiSecret ? $this->segredoJwtAdmin() : $this->segredoJwt();
        $hash   = hash_hmac('sha256', $rawToken, $secret);
        if (!hash_equals($row['token_hash'], $hash)) {
            throw new DomainException('Refresh inválido.', 401);
        }
    }

    public function revogarRefreshPorUsuario(string $userUuid): void
    {
        $this->refreshTokens?->revokeByUser($userUuid);
    }

    public function revogarRefreshPorJti(string $jti): void
    {
        $this->refreshTokens?->revokeByJti($jti);
    }

    // ── Delegações ao repositório ─────────────────────────────────────────

    /** Busca por login (e-mail ou username). Retorna AuthenticatableInterface ou null. */
    public function buscarUsuarioPorLogin(string $login): ?AuthenticatableInterface
    {
        return $this->buscarUsuario($login);
    }

    public function buscarPorUuid(string $uuid): ?AuthenticatableInterface
    {
        return $this->usuarios->buscarPorUuid($uuid);
    }

    public function buscarPorEmail(string $email): ?AuthenticatableInterface
    {
        return $this->usuarios->buscarPorEmail($email);
    }

    /**
     * Busca por token de recuperação de senha.
     * Só funciona se o repositório implementar UserRepositoryInterface completo.
     */
    public function buscarPorTokenRecuperacaoSenha(string $token): ?AuthenticatableInterface
    {
        return $this->usuarios->buscarPorTokenRecuperacaoSenha($token);
    }

    public function buscarPorTokenVerificacaoEmail(string $token): ?AuthenticatableInterface
    {
        return $this->usuarios->buscarPorTokenVerificacaoEmail($token);
    }

    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void
    {
        $this->usuarios->salvarTokenRecuperacaoSenha($uuid, $token);
    }

    /**
     * Persiste alterações na entidade (ex: nova senha após reset).
     * Delega ao repositório se ele implementar um método salvar().
     */
    public function salvar(AuthenticatableInterface $usuario): void
    {
        if (method_exists($this->usuarios, 'salvar')) {
            $this->usuarios->salvar($usuario);
        }
    }

    public function limparTokenRecuperacaoSenha(string $uuid): void
    {
        $this->usuarios->limparTokenRecuperacaoSenha($uuid);
    }

    public function marcarEmailComoVerificado(string $uuid, bool $verificado = true): void
    {
        $this->usuarios->marcarEmailComoVerificado($uuid, $verificado);
    }

    /**
     * Tenta registrar o disparo de e-mail de recuperação de forma atômica.
     * Retorna true se autorizado, false se ainda no cooldown.
     */
    public function tentarRegistrarEmailRecuperacao(string $email): bool
    {
        if (!$this->refreshTokens) return true;
        try {
            $pdo = $this->refreshTokens->getPdo();
            return (new \Src\Kernel\Support\EmailThrottle($pdo))->tryRecord('password_reset', $email);
        } catch (\Throwable) {
            return true; // fail-open
        }
    }

    public function tempoExpiracao(): int  { return $this->accessTtl; }
    public function tempoRefresh(): int    { return $this->refreshTtl; }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function buscarUsuario(string $login): ?AuthenticatableInterface
    {
        $result = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? $this->usuarios->buscarPorEmail($login)
            : $this->usuarios->buscarPorUsername($login);

        // Garante que o resultado implementa AuthenticatableInterface
        if ($result !== null && !($result instanceof AuthenticatableInterface)) {
            throw new \RuntimeException(
                'O repositório retornou um objeto que não implementa AuthenticatableInterface. ' .
                'Implemente Src\Kernel\Contracts\AuthenticatableInterface na sua entidade.'
            );
        }

        return $result;
    }

    private function segredoJwt(): string
    {
        if ($this->jwtSecret === '') throw new DomainException('JWT_SECRET não configurado.', 500);
        return $this->jwtSecret;
    }

    private function segredoJwtAdmin(): string
    {
        if ($this->jwtApiSecret === '') throw new DomainException('JWT_API_SECRET não configurado.', 500);
        return $this->jwtApiSecret;
    }
}
