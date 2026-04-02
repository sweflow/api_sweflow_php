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
    public function __construct(
        private UserRepositoryInterface $usuarios,
        private ?RefreshTokenRepository $refreshTokens = null
    )
    {
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
        $accessTtl  = $this->tempoExpiracao();
        $refreshTtl = $this->tempoRefresh();
        $agora      = time();
        $iss        = $this->emissor();
        $aud        = $this->audiencia();

        $accessJti = Uuid::uuid4()->toString();
        $accessExp = $agora + $accessTtl;
        $accessPayload = [
            'sub'          => $usuario->getUuid()->toString(),
            'email'        => $usuario->getEmail(),
            'username'     => $usuario->getUsername(),
            'nivel_acesso' => $usuario->getNivelAcesso(),
            'iat'          => $agora,
            'exp'          => $accessExp,
            'iss'          => $iss,
            'aud'          => $aud,
            'tipo'         => 'user',
            'jti'          => $accessJti,
        ];
        $access = JWT::encode($accessPayload, $secret, 'HS256');

        $refreshJti = Uuid::uuid4()->toString();
        $refreshExp = $agora + $refreshTtl;
        $refreshPayload = [
            'sub'  => $usuario->getUuid()->toString(),
            'iat'  => $agora,
            'exp'  => $refreshExp,
            'iss'  => $iss,
            'aud'  => $aud,
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
        return JWT::decode($token, new Key($this->segredoJwt(), 'HS256'));
    }

    public function decodificarRefresh(string $token): object
    {
        $payload = $this->decodificarToken($token);
        if (!isset($payload->tipo) || $payload->tipo !== 'refresh') {
            throw new DomainException('Token de refresh inválido.', 401);
        }
        return $payload;
    }

    public function validarRefreshNaoRevogado(object $payload, string $rawToken): void
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
        $hash = $this->hashToken($rawToken);
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

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, $this->segredoJwt());
    }

    public function tempoExpiracao(): int
    {
        $valor = $_ENV['JWT_EXPIRATION_TIME'] ?? getenv('JWT_EXPIRATION_TIME') ?? '900';
        $tempo = (int) $valor;
        return $tempo > 0 ? $tempo : 900;
    }

    public function tempoRefresh(): int
    {
        $valor = $_ENV['REFRESH_TOKEN_EXPIRATION_SECONDS'] ?? getenv('REFRESH_TOKEN_EXPIRATION_SECONDS') ?? '2592000';
        $tempo = (int) $valor;
        return $tempo > 0 ? $tempo : 2592000;
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
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        if ($secret === '') {
            throw new DomainException('JWT_SECRET não configurado.', 500);
        }
        return $secret;
    }

    private function segredoJwtAdmin(): string
    {
        $secret = $_ENV['JWT_API_SECRET'] ?? getenv('JWT_API_SECRET') ?? '';
        if ($secret === '') {
            throw new DomainException('JWT_API_SECRET não configurado.', 500);
        }
        return $secret;
    }

    private function emissor(): string
    {
        return $_ENV['JWT_ISSUER'] ?? getenv('JWT_ISSUER') ?? ($_ENV['APP_URL'] ?? '');
    }

    private function audiencia(): string
    {
        return $_ENV['JWT_AUDIENCE'] ?? getenv('JWT_AUDIENCE') ?? ($_ENV['APP_URL_FRONTEND'] ?? '');
    }
}
