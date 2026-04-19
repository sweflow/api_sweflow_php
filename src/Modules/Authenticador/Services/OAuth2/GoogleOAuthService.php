<?php

namespace Src\Modules\Authenticador\Services\OAuth2;

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Src\Modules\Authenticador\Bootstrap\EnvLoader;
use Src\Modules\Authenticador\Exceptions\ValidacaoException;

/**
 * Service: GoogleOAuthService
 * 
 * Serviço de autenticação OAuth2 com Google
 * Gerencia o fluxo de login social com Google
 */
class GoogleOAuthService
{
    private Google $provider;
    private bool $enabled;
    
    public function __construct()
    {
        // Carrega variáveis de ambiente do módulo
        EnvLoader::load();
        
        $this->enabled = filter_var(
            EnvLoader::get('GOOGLE_OAUTH_ENABLED', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );
        
        if (!$this->enabled) {
            return;
        }
        
        $clientId = EnvLoader::get('GOOGLE_CLIENT_ID');
        $clientSecret = EnvLoader::get('GOOGLE_CLIENT_SECRET');
        $redirectUri = EnvLoader::get('GOOGLE_REDIRECT_URI');
        
        if (!$clientId || !$clientSecret || !$redirectUri) {
            throw new ValidacaoException(
                'Configuração OAuth2 Google incompleta. Verifique GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET e GOOGLE_REDIRECT_URI no .env'
            );
        }
        
        $this->provider = new Google([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri'  => $redirectUri,
        ]);
    }
    
    /**
     * Verifica se OAuth2 Google está habilitado
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Gera URL de autorização do Google
     * 
     * @return array ['url' => string, 'state' => string]
     */
    public function getAuthorizationUrl(): array
    {
        if (!$this->enabled) {
            throw new ValidacaoException('OAuth2 Google não está habilitado');
        }
        
        $scopes = explode(',', EnvLoader::get('GOOGLE_SCOPES', 'openid,email,profile'));
        $scopes = array_map('trim', $scopes);
        
        $authUrl = $this->provider->getAuthorizationUrl([
            'scope' => $scopes,
        ]);
        
        $state = $this->provider->getState();
        
        return [
            'url' => $authUrl,
            'state' => $state,
        ];
    }
    
    /**
     * Processa callback do Google e obtém informações do usuário
     * 
     * @param string $code Código de autorização retornado pelo Google
     * @param string|null $state State para validação CSRF
     * @return array Dados do usuário Google
     * @throws ValidacaoException
     */
    public function handleCallback(string $code, ?string $state = null): array
    {
        if (!$this->enabled) {
            throw new ValidacaoException('OAuth2 Google não está habilitado');
        }
        
        try {
            // Obtém access token
            $accessToken = $this->provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);
            
            // Obtém informações do usuário
            $userData = $this->getUserInfo($accessToken);
            
            return $userData;
            
        } catch (\Exception $e) {
            throw new ValidacaoException(
                'Erro ao processar callback do Google: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Obtém informações do usuário Google usando access token
     * 
     * @param AccessToken $accessToken
     * @return array
     */
    public function getUserInfo(AccessToken $accessToken): array
    {
        try {
            /** @var GoogleUser $googleUser */
            $googleUser = $this->provider->getResourceOwner($accessToken);
            
            return [
                'provider' => 'google',
                'provider_id' => $googleUser->getId(),
                'email' => $googleUser->getEmail(),
                'email_verified' => filter_var($googleUser->toArray()['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'nome_completo' => $googleUser->getName(),
                'primeiro_nome' => $googleUser->getFirstName(),
                'sobrenome' => $googleUser->getLastName(),
                'avatar' => $googleUser->getAvatar(),
                'locale' => $googleUser->getLocale() ?? 'pt-BR',
                'raw' => $googleUser->toArray(),
            ];
            
        } catch (\Exception $e) {
            throw new ValidacaoException(
                'Erro ao obter informações do usuário Google: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Valida state para prevenir CSRF
     * 
     * @param string $receivedState State recebido do callback
     * @param string $expectedState State esperado (armazenado na sessão)
     * @return bool
     */
    public function validateState(string $receivedState, string $expectedState): bool
    {
        return hash_equals($expectedState, $receivedState);
    }
}
