<?php

namespace Src\Kernel\Support;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Centraliza a decodificação e validação de tokens JWT.
 *
 * Estratégia de dois segredos:
 *   1. JWT_API_SECRET — tokens de admin_system (tipo: 'user', assinados com secret de API)
 *   2. JWT_SECRET     — tokens de usuários comuns
 *
 * Retorna [payload, assinadoComApiSecret].
 */
final class JwtDecoder
{
    /**
     * Decodifica um token de usuário tentando JWT_API_SECRET primeiro, depois JWT_SECRET.
     *
     * @return array{0: object, 1: bool} [payload, assinadoComApiSecret]
     * @throws DomainException em caso de token inválido ou secrets não configurados
     */
    public static function decodeUser(string $token): array
    {
        $apiSecret = self::secret('JWT_API_SECRET');
        if ($apiSecret !== '') {
            try {
                $payload = JWT::decode($token, new Key($apiSecret, 'HS256'));
                if (isset($payload->tipo) && $payload->tipo === 'user') {
                    return [$payload, true];
                }
            } catch (\Throwable) {
                // não é token de admin — tenta JWT_SECRET
            }
        }

        $secret = self::secret('JWT_SECRET');
        if ($secret === '') {
            throw new DomainException('JWT_SECRET não configurado.', 500);
        }

        try {
            return [JWT::decode($token, new Key($secret, 'HS256')), false];
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
     * Valida os claims obrigatórios de um token de usuário.
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

        $iss = self::env('JWT_ISSUER');
        if ($iss !== '' && (!isset($payload->iss) || $payload->iss !== $iss)) {
            throw new DomainException('Emissor do token inválido.', 401);
        }

        $aud = self::env('JWT_AUDIENCE');
        if ($aud !== '') {
            $tokenAud = $payload->aud ?? null;
            $audMatch = is_array($tokenAud)
                ? in_array($aud, $tokenAud, true)
                : $tokenAud === $aud;
            if (!$audMatch) {
                throw new DomainException('Audiência do token inválida.', 401);
            }
        }
    }

    private static function secret(string $key): string
    {
        return trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
    }

    private static function env(string $key): string
    {
        return trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
    }
}
