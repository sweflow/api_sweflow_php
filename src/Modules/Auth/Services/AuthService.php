<?php

namespace Src\Modules\Auth\Services;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Modules\Usuario\Entities\Usuario;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Modules\Auth\Repositories\RefreshTokenRepository;
use Ramsey\Uuid\Uuid;
use DateTimeImmutable;

class AuthService
{
    // Configurações cacheadas no construtor — evita leitura de $_ENV a cada chamada
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
    }

    private function lerEnv(string $key, string $default): string
    {
        return trim((string) ($_ENV[$key] ?? getenv($key) ?: $default));
    }

    public function autenticar(string $login, string $senha): Usuario
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

    public function emitirTokens(Usuario $usuario): array
    {
        return $this->emitirTokensComSecret($usuario, $this->segredoJwt());
    }

    /**
     * Emite tokens para admin_system assinados com JWT_API_SECRET.
     * Esses tokens são aceitos pelas rotas protegidas com AdminOnlyMiddleware.
     */
    public function emitirTokensAdmin(Usuario $usuario): array
    {
        return $this->emitirTokensComSecret($usuario, $this->segredoJwtAdmin());
    }

    private function emitirTokensComSecret(Usuario $usuario, string $secret): array
    {
        $agora = time();

        $accessJti = Uuid::uuid4()->toString();
        $accessExp = $agora + $this->accessTtl;
        $accessPayload = [
            'sub'          => $usuario->getUuid()->toString(),
            'email'        => $usuario->getEmail(),
            'username'     => $usuario->getUsername(),
            'nivel_acesso' => $usuario->getNivelAcesso(),
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
            'sub'  => $usuario->getUuid()->toString(),
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
                $usuario->getUuid()->toString(),
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

    public function decodificarToken(string $token): object
    {
        // Tenta JWT_API_SECRET primeiro (admin_system), depois JWT_SECRET.
        // Rejeita explicitamente tokens do tipo 'refresh' — não são access tokens.
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

    /**
     * Decodifica um refresh token sem rejeitar o tipo 'refresh'.
     * Uso exclusivo para o fluxo de renovação de tokens.
     */
    public function decodificarRefresh(string $token): object
    {
        [$payload] = $this->decodificarRefreshComSecret($token);
        return $payload;
    }

    /**
     * Decodifica um refresh token e retorna [payload, assinadoComApiSecret].
     * Permite que o caller saiba qual secret usar para validar o HMAC.
     *
     * @return array{0: object, 1: bool}
     */
    public function decodificarRefreshComSecret(string $token): array
    {
        // Tenta JWT_API_SECRET primeiro, depois JWT_SECRET
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

    public function validarRefreshNaoRevogado(object $payload, string $rawToken, bool $assinadoComApiSecret = false): void
    {
        if (!$this->refreshTokens) {
            return;
        }
        $jti = $payload->jti ?? '';
        if ($jti === '') {
            throw new DomainException('Refresh sem jti.', 401);
        }
        $row = $this->refreshTokens->findValidByJti($jti);
        if (!$row) {
            throw new DomainException('Refresh revogado ou expirado.', 401);
        }
        // Usa o secret correto diretamente — sem re-decodificar o token
        $secret = $assinadoComApiSecret ? $this->segredoJwtAdmin() : $this->segredoJwt();
        $hash   = hash_hmac('sha256', $rawToken, $secret);
        if (!hash_equals($row['token_hash'], $hash)) {
            throw new DomainException('Refresh inválido.', 401);
        }
    }

    public function revogarRefreshPorUsuario(string $userUuid): void
    {
        if ($this->refreshTokens) {
            $this->refreshTokens->revokeByUser($userUuid);
        }
    }

    public function revogarRefreshPorJti(string $jti): void
    {
        if ($this->refreshTokens) {
            $this->refreshTokens->revokeByJti($jti);
        }
    }

    // ── Delegações ao repositório (evita Service Locator nos controllers) ─

    public function buscarUsuarioPorLogin(string $login): ?Usuario
    {
        return $this->buscarUsuario($login);
    }

    public function buscarPorUuid(string $uuid): ?Usuario
    {
        return $this->usuarios->buscarPorUuid($uuid);
    }

    public function buscarPorEmail(string $email): ?Usuario
    {
        return $this->usuarios->buscarPorEmail($email);
    }

    public function buscarPorTokenRecuperacaoSenha(string $token): ?Usuario
    {
        if (!$this->usuarios instanceof \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface) {
            return null;
        }
        return $this->usuarios->buscarPorTokenRecuperacaoSenha($token);
    }

    public function buscarPorTokenVerificacaoEmail(string $token): ?Usuario
    {
        if (!$this->usuarios instanceof \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface) {
            return null;
        }
        return $this->usuarios->buscarPorTokenVerificacaoEmail($token);
    }

    public function salvar(Usuario $usuario): void
    {
        if (!$this->usuarios instanceof \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface) {
            return;
        }
        $this->usuarios->salvar($usuario);
    }

    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void
    {
        if (!$this->usuarios instanceof \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface) {
            return;
        }
        $this->usuarios->salvarTokenRecuperacaoSenha($uuid, $token);
    }

    public function limparTokenRecuperacaoSenha(string $uuid): void
    {
        if (!$this->usuarios instanceof \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface) {
            return;
        }
        $this->usuarios->limparTokenRecuperacaoSenha($uuid);
    }

    public function marcarEmailComoVerificado(string $uuid, bool $verificado = true): void
    {
        if (!$this->usuarios instanceof \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface) {
            return;
        }
        $this->usuarios->marcarEmailComoVerificado($uuid, $verificado);
    }

    /**
     * Tenta registrar o disparo de e-mail de recuperação de forma atômica.
     * Retorna true se o envio foi autorizado e registrado, false se ainda no cooldown.
     * Substitui o par podeDispararEmailRecuperacao() + registrarDisparoEmailRecuperacao().
     */
    public function tentarRegistrarEmailRecuperacao(string $email): bool
    {
        if (!$this->refreshTokens) {
            return true;
        }
        try {
            $pdo = $this->refreshTokens->getPdo();
            return (new \Src\Kernel\Support\EmailThrottle($pdo))->tryRecord('password_reset', $email);
        } catch (\Throwable) {
            return true; // fail-open
        }
    }

    public function tempoExpiracao(): int
    {
        return $this->accessTtl;
    }

    public function tempoRefresh(): int
    {
        return $this->refreshTtl;
    }

    private function buscarUsuario(string $login): ?Usuario
    {
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return $this->usuarios->buscarPorEmail($login);
        }

        return $this->usuarios->buscarPorUsername($login);
    }

    private function segredoJwt(): string
    {
        if ($this->jwtSecret === '') {
            throw new DomainException('JWT_SECRET não configurado.', 500);
        }
        return $this->jwtSecret;
    }

    private function segredoJwtAdmin(): string
    {
        if ($this->jwtApiSecret === '') {
            throw new DomainException('JWT_API_SECRET não configurado.', 500);
        }
        return $this->jwtApiSecret;
    }
}
