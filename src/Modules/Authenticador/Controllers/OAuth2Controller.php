<?php

namespace Src\Modules\Authenticador\Controllers;

use PDO;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Authenticador\Services\OAuth2\GoogleOAuthService;
use Src\Modules\Authenticador\Services\AuthenticationService;
use Src\Modules\Authenticador\Integracao\Usuario2Integrador;
use Src\Modules\Authenticador\Exceptions\ValidacaoException;

/**
 * Controller: OAuth2Controller
 * 
 * Gerencia autenticação OAuth2 com provedores externos (Google, Facebook, GitHub)
 */
class OAuth2Controller
{
    private PDO $pdo;
    private GoogleOAuthService $googleService;
    private AuthenticationService $authService;
    private Usuario2Integrador $usuario2Integrador;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->googleService = new GoogleOAuthService();
        $this->authService = new AuthenticationService($pdo);
        $this->usuario2Integrador = new Usuario2Integrador($pdo);
    }
    
    /**
     * Redireciona para página de login do Google
     * 
     * GET /api/auth/google
     */
    public function redirectToGoogle($request): Response
    {
        try {
            if (!$this->googleService->isEnabled()) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Login com Google não está habilitado',
                ], 400);
            }
            
            $authData = $this->googleService->getAuthorizationUrl();
            
            // Armazena state na sessão para validação CSRF
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['oauth2_state'] = $authData['state'];
            
            return Response::json([
                'status' => 'success',
                'data' => [
                    'authorization_url' => $authData['url'],
                    'state' => $authData['state'],
                ],
            ]);
            
        } catch (ValidacaoException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro ao gerar URL de autorização',
            ], 500);
        }
    }
    
    /**
     * Processa callback do Google após autorização
     * 
     * GET /api/auth/google/callback?code=...&state=...
     */
    public function handleGoogleCallback($request): Response
    {
        try {
            if (!$this->googleService->isEnabled()) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Login com Google não está habilitado',
                ], 400);
            }
            
            $code = $request['query']['code'] ?? null;
            $state = $request['query']['state'] ?? null;
            
            if (!$code) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Código de autorização não fornecido',
                ], 400);
            }
            
            // Valida state para prevenir CSRF
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $expectedState = $_SESSION['oauth2_state'] ?? null;
            
            if (!$state || !$expectedState || !$this->googleService->validateState($state, $expectedState)) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'State inválido. Possível ataque CSRF',
                ], 400);
            }
            
            // Remove state da sessão
            unset($_SESSION['oauth2_state']);
            
            // Obtém dados do usuário Google
            $googleUserData = $this->googleService->handleCallback($code, $state);
            
            // Busca ou cria usuário
            $usuario = $this->usuario2Integrador->findOrCreateFromOAuth($googleUserData);
            
            // Gera tokens JWT
            $tokens = $this->authService->login($usuario->getEmail(), null, true); // true = OAuth login
            
            return Response::json([
                'status' => 'success',
                'message' => 'Login com Google realizado com sucesso',
                'data' => $tokens,
            ]);
            
        } catch (ValidacaoException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro ao processar callback do Google',
                'debug' => $_ENV['APP_DEBUG'] === 'true' ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Vincula conta Google a usuário existente
     * 
     * POST /api/auth/google/link
     * Body: { "code": "...", "state": "..." }
     * Headers: Authorization: Bearer <token>
     */
    public function linkGoogleAccount($request): Response
    {
        try {
            if (!$this->googleService->isEnabled()) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Login com Google não está habilitado',
                ], 400);
            }
            
            // Obtém usuário autenticado
            $usuarioUuid = $request['auth']['usuario']['uuid'] ?? null;
            
            if (!$usuarioUuid) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Usuário não autenticado',
                ], 401);
            }
            
            $body = $request['body'] ?? [];
            $code = $body['code'] ?? null;
            $state = $body['state'] ?? null;
            
            if (!$code) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Código de autorização não fornecido',
                ], 400);
            }
            
            // Obtém dados do usuário Google
            $googleUserData = $this->googleService->handleCallback($code, $state);
            
            // Vincula conta Google ao usuário
            $this->usuario2Integrador->linkOAuthAccount($usuarioUuid, $googleUserData);
            
            return Response::json([
                'status' => 'success',
                'message' => 'Conta Google vinculada com sucesso',
            ]);
            
        } catch (ValidacaoException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro ao vincular conta Google',
            ], 500);
        }
    }
    
    /**
     * Desvincula conta Google do usuário
     * 
     * DELETE /api/auth/google/unlink
     * Headers: Authorization: Bearer <token>
     */
    public function unlinkGoogleAccount($request): Response
    {
        try {
            // Obtém usuário autenticado
            $usuarioUuid = $request['auth']['usuario']['uuid'] ?? null;
            
            if (!$usuarioUuid) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Usuário não autenticado',
                ], 401);
            }
            
            // Desvincula conta Google
            $this->usuario2Integrador->unlinkOAuthAccount($usuarioUuid, 'google');
            
            return Response::json([
                'status' => 'success',
                'message' => 'Conta Google desvinculada com sucesso',
            ]);
            
        } catch (ValidacaoException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro ao desvincular conta Google',
            ], 500);
        }
    }
}
