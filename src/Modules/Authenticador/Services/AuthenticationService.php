<?php

namespace Src\Modules\Authenticador\Services;

use PDO;
use Src\Modules\Authenticador\Integracao\Usuario2Integrador;

/**
 * Service: AuthenticationService
 * 
 * Serviço principal de autenticação que integra TokenService com Usuarios2
 * Fornece uma camada de abstração para autenticação completa
 */
class AuthenticationService
{
    private PDO $pdo;
    private TokenService $tokenService;
    private Usuario2Integrador $usuario2Integrador;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->tokenService = new TokenService($pdo);
        $this->usuario2Integrador = new Usuario2Integrador($pdo);
    }
    
    /**
     * Realiza login e retorna tokens JWT
     * 
     * @param string $identificador Email ou username do usuário
     * @param string|null $senha Senha do usuário (null para OAuth login)
     * @param bool $isOAuth Se true, pula validação de senha
     * @return array ['access_token', 'refresh_token', 'usuario']
     */
    public function login(string $identificador, ?string $senha = null, bool $isOAuth = false): array
    {
        if ($isOAuth) {
            // Login OAuth - busca usuário por email
            $usuario = $this->usuario2Integrador->buscarPorEmail($identificador);
            
            if (!$usuario) {
                throw new \Exception('Usuário não encontrado');
            }
        } else {
            // Login tradicional - valida credenciais
            $usuario = $this->usuario2Integrador->validarCredenciais($identificador, $senha);
        }
        
        // Atualiza último login
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $this->usuario2Integrador->atualizarUltimoLogin($usuario->getUuid(), $ip, $userAgent);
        
        // Gera tokens JWT
        $accessToken = $this->tokenService->gerarToken($usuario->getUuid());
        $refreshToken = $this->tokenService->gerarRefreshToken($usuario->getUuid());
        
        return [
            'access_token' => $accessToken['token'],
            'refresh_token' => $refreshToken['token'],
            'expires_in' => $accessToken['expires_in'],
            'token_type' => 'Bearer',
            'usuario' => $usuario->toArray(),
        ];
    }
    
    /**
     * Realiza logout e revoga tokens
     * 
     * @param string $token Token JWT atual
     * @return bool
     */
    public function logout(string $token): bool
    {
        // Revoga o token atual
        return $this->tokenService->revogarToken($token, 'Logout');
    }
    
    /**
     * Realiza logout de todas as sessões do usuário
     * 
     * @param string $usuarioUuid UUID do usuário
     * @return int Número de tokens revogados
     */
    public function logoutTodos(string $usuarioUuid): int
    {
        // Revoga todos os tokens
        return $this->tokenService->revogarTodosTokensDoUsuario(
            $usuarioUuid,
            'Logout de todas as sessões'
        );
    }
    
    /**
     * Renova um access token usando refresh token
     * 
     * @param string $refreshToken Refresh token JWT
     * @return array|null Novo access token ou null se inválido
     */
    public function renovarToken(string $refreshToken): ?array
    {
        $novoToken = $this->tokenService->renovarToken($refreshToken);
        
        if (!$novoToken) {
            return null;
        }
        
        return [
            'access_token' => $novoToken['token'],
            'expires_in' => $novoToken['expires_in'],
            'token_type' => 'Bearer',
        ];
    }
    
    /**
     * Valida um token e retorna informações do usuário
     * 
     * @param string $token Token JWT
     * @return array|null ['usuario', 'payload'] ou null se inválido
     */
    public function validarToken(string $token): ?array
    {
        $payload = $this->tokenService->validarToken($token);
        
        if (!$payload) {
            return null;
        }
        
        // Busca o usuário
        $usuario = $this->usuario2Integrador->buscarPorUuid($payload['sub']);
        
        if (!$usuario) {
            return null;
        }
        
        return [
            'usuario' => $usuario->toArray(),
            'payload' => $payload,
        ];
    }
}
