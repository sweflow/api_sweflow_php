<?php

namespace Src\Modules\Authenticador\Services;

use PDO;
use DateTime;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Src\Modules\Authenticador\Entities\AuthToken;
use Src\Modules\Authenticador\Repositories\AuthTokenRepository;

/**
 * Service: TokenService
 * 
 * Gerencia geração, validação e revogação de tokens JWT
 */
class TokenService
{
    private PDO $pdo;
    private AuthTokenRepository $repository;
    private string $jwtSecret;
    private string $jwtAlgorithm = 'HS256';
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->repository = new AuthTokenRepository($pdo);
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? 'default-secret-change-me';
    }
    
    /**
     * Gera um novo token JWT para um usuário
     * 
     * @param string $usuarioUuid UUID do usuário
     * @param int $expiresInSeconds Tempo de expiração em segundos (padrão: 1 hora)
     * @param string $tokenType Tipo do token (access, refresh)
     * @return array ['token' => string, 'expires_at' => DateTime]
     */
    public function gerarToken(
        string $usuarioUuid,
        int $expiresInSeconds = 3600,
        string $tokenType = 'access'
    ): array {
        $now = new DateTime();
        $expiresAt = (clone $now)->modify("+{$expiresInSeconds} seconds");
        
        // Payload do JWT
        $payload = [
            'iss' => $_ENV['APP_URL'] ?? 'vupi.us',
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'sub' => $usuarioUuid,
            'type' => $tokenType,
            'jti' => $this->gerarJti(),
        ];
        
        // Gera o JWT
        $jwt = JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);
        
        // Armazena o hash do token no banco
        $tokenHash = hash('sha256', $jwt);
        
        $authToken = new AuthToken();
        $authToken->setUsuarioUuid($usuarioUuid)
            ->setTokenHash($tokenHash)
            ->setTokenType($tokenType)
            ->setExpiresAt($expiresAt)
            ->setCreatedAt($now)
            ->setIpAddress($_SERVER['REMOTE_ADDR'] ?? null)
            ->setUserAgent($_SERVER['HTTP_USER_AGENT'] ?? null);
        
        $this->repository->criar($authToken);
        
        return [
            'token' => $jwt,
            'expires_at' => $expiresAt,
            'expires_in' => $expiresInSeconds,
        ];
    }
    
    /**
     * Valida um token JWT
     * 
     * @param string $jwt Token JWT
     * @return array|null Payload do token ou null se inválido
     */
    public function validarToken(string $jwt): ?array
    {
        try {
            // Decodifica o JWT
            $decoded = JWT::decode($jwt, new Key($this->jwtSecret, $this->jwtAlgorithm));
            $payload = (array)$decoded;
            
            // Verifica se o token está no banco e não foi revogado
            $tokenHash = hash('sha256', $jwt);
            $authToken = $this->repository->buscarPorHash($tokenHash);
            
            if (!$authToken || !$authToken->isValid()) {
                return null;
            }
            
            // Atualiza o último uso
            $authToken->atualizarUltimoUso();
            $this->repository->atualizar($authToken);
            
            return $payload;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Revoga um token
     * 
     * @param string $jwt Token JWT
     * @param string $motivo Motivo da revogação
     * @return bool
     */
    public function revogarToken(string $jwt, string $motivo = null): bool
    {
        $tokenHash = hash('sha256', $jwt);
        $authToken = $this->repository->buscarPorHash($tokenHash);
        
        if (!$authToken) {
            return false;
        }
        
        return $this->repository->revogar($authToken->getUuid(), $motivo);
    }
    
    /**
     * Revoga todos os tokens de um usuário
     * 
     * @param string $usuarioUuid UUID do usuário
     * @param string $motivo Motivo da revogação
     * @return int Número de tokens revogados
     */
    public function revogarTodosTokensDoUsuario(string $usuarioUuid, string $motivo = null): int
    {
        return $this->repository->revogarTodosDoUsuario($usuarioUuid, $motivo);
    }
    
    /**
     * Lista todos os tokens de um usuário
     * 
     * @param string $usuarioUuid UUID do usuário
     * @param bool $apenasValidos Se true, retorna apenas tokens válidos
     * @return array
     */
    public function listarTokensDoUsuario(string $usuarioUuid, bool $apenasValidos = false): array
    {
        $tokens = $this->repository->buscarPorUsuario($usuarioUuid, $apenasValidos);
        
        return array_map(function (AuthToken $token) {
            return $token->toArray();
        }, $tokens);
    }
    
    /**
     * Limpa tokens expirados do banco
     * 
     * @return int Número de tokens removidos
     */
    public function limparTokensExpirados(): int
    {
        return $this->repository->limparExpirados();
    }
    
    /**
     * Limpa tokens revogados antigos
     * 
     * @param int $dias Número de dias
     * @return int Número de tokens removidos
     */
    public function limparTokensRevogadosAntigos(int $dias = 30): int
    {
        return $this->repository->limparRevogadosAntigos($dias);
    }
    
    /**
     * Gera um refresh token
     * 
     * @param string $usuarioUuid UUID do usuário
     * @return array
     */
    public function gerarRefreshToken(string $usuarioUuid): array
    {
        // Refresh token dura 30 dias
        return $this->gerarToken($usuarioUuid, 30 * 24 * 3600, 'refresh');
    }
    
    /**
     * Renova um access token usando um refresh token
     * 
     * @param string $refreshToken Refresh token JWT
     * @return array|null Novo access token ou null se inválido
     */
    public function renovarToken(string $refreshToken): ?array
    {
        $payload = $this->validarToken($refreshToken);
        
        if (!$payload || $payload['type'] !== 'refresh') {
            return null;
        }
        
        // Gera um novo access token
        return $this->gerarToken($payload['sub']);
    }
    
    /**
     * Gera um JTI (JWT ID) único
     */
    private function gerarJti(): string
    {
        return bin2hex(random_bytes(16));
    }
}
