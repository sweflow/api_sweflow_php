<?php

namespace Src\Kernel\Support;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\SecurityEventLogger;

/**
 * Centraliza a decodificação e validação de tokens JWT.
 *
 * Estratégia de dois segredos + key rotation via kid:
 *   JWT_API_SECRET   — tokens de admin_system
 *   JWT_SECRET       — tokens de usuários comuns
 *
 * Key rotation (sem derrubar usuários):
 *   JWT_SECRET_v1    — secret anterior (aceita tokens antigos)
 *   JWT_SECRET_v2    — secret atual (emite novos tokens)
 *   JWT_SECRET       — aponta para o atual (alias)
 *
 * Exemplo .env para rotação:
 *   JWT_SECRET=<novo_secret>
 *   JWT_SECRET_v1=<secret_anterior>
 *   JWT_SECRET_v2=<novo_secret>
 *
 * O kid no header do JWT indica qual versão usar.
 * Tokens sem kid usam JWT_SECRET (compatibilidade).
 */
final class JwtDecoder
{
    /** Algoritmos JWT aceitos. Altere aqui se migrar para RS256 no futuro. */
    private const ALLOWED_ALGS = ['HS256'];
    /**
     * Decodifica um token de usuário.
     * Tenta JWT_API_SECRET primeiro (admin), depois JWT_SECRET com suporte a kid rotation.
     *
     * @return array{0: object, 1: bool} [payload, assinadoComApiSecret]
     * @throws DomainException em caso de token inválido
     */
    public static function decodeUser(string $token): array
    {
        self::validateAlgorithm($token);

        // Tenta JWT_API_SECRET primeiro (admin_system)
        $apiSecret = self::secret('JWT_API_SECRET');
        if ($apiSecret !== '') {
            try {
                $payload = JWT::decode($token, new Key($apiSecret, 'HS256'));
                if (isset($payload->tipo) && $payload->tipo === 'user') {
                    return [$payload, true];
                }
            } catch (\Throwable) {
                // não é token de admin — continua
            }
        }

        // Tenta JWT_SECRET com suporte a key rotation via kid
        $keys = self::buildKeyMap();
        if (empty($keys)) {
            throw new DomainException('JWT_SECRET não configurado.', 500);
        }

        // Extrai kid do header sem validar assinatura (para selecionar a chave correta)
        $kid = self::extractKid($token);

        try {
            if ($kid !== null && isset($keys[$kid])) {
                // Token com kid explícito — usa a chave correspondente
                $payload = JWT::decode($token, new Key($keys[$kid], 'HS256'));
            } else {
                // Token sem kid ou kid desconhecido — tenta todas as chaves (compatibilidade)
                $payload = self::decodeWithAnyKey($token, $keys);
            }
            return [$payload, false];
        } catch (DomainException $e) {
            throw new DomainException('Token inválido: ' . $e->getMessage(), 401, $e);
        } catch (\Throwable $e) {
            throw new DomainException('Token inválido ou expirado.', 401, $e);
        }
    }

    /**
     * Verifica se o token é um token de API válido (JWT_API_SECRET, tipo: 'api').
     */
    public static function isApiToken(string $token): bool
    {
        $secret = self::secret('JWT_API_SECRET');
        if ($secret === '') {
            return false;
        }
        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
            return !empty($payload->api_access) || ($payload->tipo ?? '') === 'api';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifica se o token foi assinado com JWT_API_SECRET.
     */
    public static function isSignedWithApiSecret(string $token): bool
    {
        $secret = self::secret('JWT_API_SECRET');
        if ($secret === '') {
            return false;
        }
        try {
            JWT::decode($token, new Key($secret, 'HS256'));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retorna o kid (key ID) atual para emissão de novos tokens.
     * Usa JWT_SECRET_KID se configurado, senão 'default'.
     */
    public static function currentKid(): ?string
    {
        $kid = trim($_ENV['JWT_SECRET_KID'] ?? getenv('JWT_SECRET_KID') ?: '');
        return $kid !== '' ? $kid : null;
    }

    /**
     * Valida os claims obrigatórios de um token de usuário.
     * iss e aud são SEMPRE validados se configurados — sem exceção.
     *
     * @throws DomainException se algum claim estiver ausente ou inválido
     */
    public static function validateUserClaims(object $payload): void
    {
        if (!isset($payload->sub)) {
            throw new DomainException('Token sem identificador de usuário.', 401);
        }
        if (!isset($payload->tipo) || $payload->tipo !== 'user') {
            throw new DomainException('Token não é de usuário.', 401);
        }
        if (empty($payload->jti)) {
            throw new DomainException('Token sem jti.', 401);
        }

        // iss: sempre validado se JWT_ISSUER configurado
        $iss = self::secret('JWT_ISSUER');
        if ($iss !== '') {
            if (!isset($payload->iss) || $payload->iss !== $iss) {
                SecurityEventLogger::auth('jwt.invalid_issuer', IpResolver::resolve(), [
                    'expected' => $iss,
                    'received' => $payload->iss ?? 'missing',
                ]);
                throw new DomainException('Emissor do token inválido.', 401);
            }
        }

        // aud: sempre validado se JWT_AUDIENCE configurado
        $aud = self::secret('JWT_AUDIENCE');
        if ($aud !== '') {
            $tokenAud = $payload->aud ?? null;
            $audMatch = is_array($tokenAud)
                ? in_array($aud, $tokenAud, true)
                : $tokenAud === $aud;
            if (!$audMatch) {
                SecurityEventLogger::auth('jwt.invalid_audience', IpResolver::resolve(), [
                    'expected' => $aud,
                    'received' => is_array($tokenAud) ? implode(',', $tokenAud) : ($tokenAud ?? 'missing'),
                ]);
                throw new DomainException('Audiência do token inválida.', 401);
            }
        }

        // Valida sub como UUID (via Ramsey\Uuid para precisão)
        if (!Uuid::isValid($payload->sub ?? '')) {
            throw new DomainException('Token inválido: sub malformado.', 401);
        }

        // Valida jti como UUID (via Ramsey\Uuid para precisão)
        if (!Uuid::isValid($payload->jti ?? '')) {
            throw new DomainException('Token inválido: jti malformado.', 401);
        }

        // nbf (Not Before) — tolerância de 30s para clock skew
        if (isset($payload->nbf) && $payload->nbf > time() + 30) {
            throw new DomainException('Token ainda não é válido.', 401);
        }

        // exp: Firebase JWT já valida, mas verificamos explicitamente para log
        if (isset($payload->exp) && $payload->exp < time()) {
            throw new DomainException('Token expirado.', 401);
        }
    }

    // ── Helpers privados ──────────────────────────────────────────────

    /**
     * Valida que o algoritmo no header JWT está na lista de permitidos.
     * Previne ataques de confusão de algoritmo (alg:none, RS256, etc.).
     */
    private static function validateAlgorithm(string $token): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new DomainException('Token malformado.', 401);
        }
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($header) || !in_array($header['alg'] ?? '', self::ALLOWED_ALGS, true)) {
            throw new DomainException('Algoritmo JWT não permitido.', 401);
        }
    }

    /**
     * Constrói o mapa kid → secret para suporte a key rotation.
     *
     * Lê JWT_SECRET (atual) + JWT_SECRET_v1, JWT_SECRET_v2, ... (histórico).
     * Também aceita JWT_SECRET_KID para nomear o secret atual.
     */
    private static function buildKeyMap(): array
    {
        $keys = [];

        // Secret atual
        $current = self::secret('JWT_SECRET');
        if ($current !== '') {
            $kid = self::currentKid() ?? 'default';
            $keys[$kid] = $current;
        }

        // Secrets históricos para rotação (JWT_SECRET_v1, JWT_SECRET_v2, ...)
        for ($i = 1; $i <= 10; $i++) {
            $s = self::secret("JWT_SECRET_v{$i}");
            if ($s !== '') {
                $keys["v{$i}"] = $s;
            }
        }

        return $keys;
    }

    /**
     * Extrai o kid do header JWT sem validar assinatura.
     */
    private static function extractKid(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $kid = $header['kid'] ?? null;
        return is_string($kid) && $kid !== '' ? $kid : null;
    }

    /**
     * Tenta decodificar com qualquer chave disponível (compatibilidade sem kid).
     * Lança exceção se nenhuma funcionar.
     */
    private static function decodeWithAnyKey(string $token, array $keys): object
    {
        $lastException = null;
        foreach ($keys as $secret) {
            try {
                return JWT::decode($token, new Key($secret, 'HS256'));
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }
        throw $lastException ?? new DomainException('Token inválido.', 401);
    }

    private static function secret(string $key): string
    {
        return trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
    }
}
