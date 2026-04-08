<?php

namespace Src\Modules\Auth\Controllers;

use DomainException;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\AuditLogger;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\JwtDecoder;
use Src\Kernel\Support\RequestContext;
use Src\Kernel\Support\ThreatScorer;
use Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository;
use Src\Modules\Auth\Repositories\RefreshTokenRepository;
use Src\Modules\Auth\Services\AuthService;
use Src\Kernel\Contracts\EmailSenderInterface;

class AuthController
{
    private bool $debug;

    public function __construct(
        private readonly AuthService                    $authService,
        private readonly RefreshTokenRepository         $refreshTokenRepository,
        private readonly AccessTokenBlacklistRepository $accessBlacklist,
        private readonly AuditLogger                    $auditLogger,
        private readonly ThreatScorer                   $threatScorer,
        private readonly ?EmailSenderInterface          $emailService = null,
        private readonly ?RequestContext                $requestContext = null,
    ) {
        $this->debug = in_array(
            strtolower(trim($_ENV['APP_DEBUG'] ?? (string) getenv('APP_DEBUG') ?: 'false')),
            ['1', 'true', 'on', 'yes'],
            true
        );
    }

    // ── Login ─────────────────────────────────────────────────────────────

    /** /api/auth/login — exclusivo para admin_system */
    public function login(Request $request): Response
    {
        return $this->processarLogin($request, true);
    }

    /** /api/login — aceita qualquer nível */
    public function loginPublic(Request $request): Response
    {
        return $this->processarLogin($request, false);
    }

    private function processarLogin(Request $request, bool $apenasAdmin): Response
    {
        $startTime = microtime(true);
        $contexto  = $apenasAdmin ? 'AuthController::login' : 'AuthController::loginPublic';

        if (\Src\Kernel\Support\CookieConfig::requiresHttps() && !\Src\Kernel\Support\CookieConfig::isHttps()) {
            return Response::json(['status' => 'error', 'message' => 'Login requer conexão segura (HTTPS).'], 403);
        }

        try {
            $this->refreshTokenRepository->purgeExpired();
            $this->accessBlacklist->purgeExpired();

            $body  = $request->body;
            $login = trim((string) ($body['login'] ?? $body['identifier'] ?? $body['email'] ?? $body['username'] ?? ''));
            $senha = (string) ($body['senha'] ?? $body['password'] ?? '');

            // Remove null bytes e caracteres de controle antes de usar em logs
            $login = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $login);
            $login = mb_substr($login, 0, 254);

            if (trim($login) === '' || trim($senha) === '') {
                $this->enforceMinResponseTime($startTime);
                throw new DomainException('Login e senha são obrigatórios.', 400);
            }

            $usuario = $apenasAdmin
                ? $this->authService->autenticar($login, $senha)
                : $this->buscarEValidarCredenciais($login, $senha, $startTime);

            if ($apenasAdmin && $usuario->getNivelAcesso() !== 'admin_system') {
                $this->enforceMinResponseTime($startTime);
                return Response::json(['status' => 'error', 'message' => 'Acesso restrito.'], 403);
            }

            if ($this->carregarPoliticaVerificacaoEmail() && !$usuario->isEmailVerificado()) {
                $this->enforceMinResponseTime($startTime);
                return Response::json([
                    'status'             => 'error',
                    'message'            => 'Você precisa confirmar seu e-mail antes de fazer login.',
                    'email_not_verified' => true,
                    'email'              => $usuario->getEmail(),
                ], 403);
            }

            $tokens = $usuario->getNivelAcesso() === 'admin_system'
                ? $this->authService->emitirTokensAdmin($usuario)
                : $this->authService->emitirTokens($usuario);

            $this->definirCookieAuth($tokens['access_token'], $tokens['access_expira_em']);
            $this->auditLogger->registrar('auth.login.success', $usuario->getUuid()->toString(), [
                'username'     => $usuario->getUsername(),
                'nivel_acesso' => $usuario->getNivelAcesso(),
            ]);

            $this->enforceMinResponseTime($startTime);
            return Response::json([
                'status'             => 'success',
                'access_token'       => $tokens['access_token'],
                'expires_in'         => $tokens['access_expira_em'],
                'refresh_token'      => $tokens['refresh_token'],
                'refresh_expires_in' => $tokens['refresh_expira_em'],
                'usuario' => [
                    'uuid'          => $usuario->getUuid()->toString(),
                    'nome_completo' => $usuario->getNomeCompleto(),
                    'username'      => $usuario->getUsername(),
                    'email'         => $usuario->getEmail(),
                    'nivel_acesso'  => $usuario->getNivelAcesso(),
                ],
            ]);

        } catch (DomainException $e) {
            $this->auditLogger->registrar('auth.login.failed', null, ['reason' => $e->getMessage()]);
            $this->threatScorer->add(IpResolver::resolve(), ThreatScorer::SCORE_LOGIN_FAIL);
            $this->enforceMinResponseTime($startTime);
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\RuntimeException $e) {
            $this->enforceMinResponseTime($startTime);
            error_log("[{$contexto}] " . get_class($e) . ': ' . $e->getMessage());
            if ($e->getCode() === 503) {
                return Response::json([
                    'status'  => 'error',
                    'message' => explode("\n", $e->getMessage())[0],
                    'code'    => 'DB_CONFIG_ERROR',
                ], 503);
            }
            return Response::json(['status' => 'error', 'message' => 'Erro interno.'], 500);
        } catch (\Throwable $e) {
            $this->enforceMinResponseTime($startTime);
            error_log("[{$contexto}] " . get_class($e) . ': ' . $e->getMessage());
            return Response::json(['status' => 'error', 'message' => 'Erro interno.'], 500);
        }
    }

    private function buscarEValidarCredenciais(string $login, string $senha, float $startTime): \Src\Modules\Usuario\Entities\Usuario
    {
        $usuario = $this->authService->buscarUsuarioPorLogin($login);
        if (!$usuario || !$usuario->verificarSenha($senha)) {
            $this->auditLogger->registrar('auth.login.failed', null, ['identifier' => substr($login, 0, 64)]);
            $this->threatScorer->add(IpResolver::resolve(), ThreatScorer::SCORE_LOGIN_FAIL);
            $this->enforceMinResponseTime($startTime);
            throw new DomainException('Credenciais inválidas.', 401);
        }
        if (!$usuario->isAtivo()) {
            $this->enforceMinResponseTime($startTime);
            throw new DomainException('Usuário desativado.', 403);
        }
        return $usuario;
    }

    // ── Recuperação de senha ──────────────────────────────────────────────

    public function solicitarRecuperacaoSenha(Request $request): Response
    {
        $startTime = microtime(true);
        try {
            $email = trim((string) ($request->body['email'] ?? ''));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->enforceMinResponseTime($startTime);
                return Response::json(['status' => 'error', 'message' => 'E-mail inválido ou não informado.'], 400);
            }

            $usuario = $this->authService->buscarPorEmail($email);

            if ($usuario && $this->emailModuleEnabled() && $this->emailService !== null) {
                $token = bin2hex(random_bytes(32));
                $this->authService->salvarTokenRecuperacaoSenha($usuario->getUuid()->toString(), $token);

                // tryRecord é atômico — verifica cooldown e registra em uma única operação
                if ($this->authService->tentarRegistrarEmailRecuperacao($email)) {
                    try {
                        $this->emailService->sendPasswordReset(
                            $usuario->getEmail(),
                            $usuario->getNomeCompleto(),
                            $this->montarLinkRecuperacaoSenha($token),
                            $_ENV['APP_LOGO_URL'] ?? null
                        );
                    } catch (\Throwable $mailError) {
                        if ($this->debugAtivo()) {
                            error_log('[AuthController] Falha ao enviar e-mail de recuperação: ' . $mailError->getMessage());
                        }
                    }
                }
            } elseif ($usuario && !$this->emailModuleEnabled()) {
                $token = bin2hex(random_bytes(32));
                $this->authService->salvarTokenRecuperacaoSenha($usuario->getUuid()->toString(), $token);
            }

            $this->enforceMinResponseTime($startTime);
            return Response::json([
                'status'  => 'success',
                'message' => 'Se o e-mail informado estiver cadastrado, um link de recuperação será enviado.',
            ]);
        } catch (\Throwable $e) {
            $this->enforceMinResponseTime($startTime);
            return Response::json([
                'status'  => 'error',
                'message' => 'Não foi possível iniciar a recuperação de senha.',
                'details' => $this->debugAtivo() ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function resetarSenha(Request $request): Response
    {
        try {
            $body      = $request->body;
            $token     = trim((string) ($body['token'] ?? ''));
            $novaSenha = \Src\Kernel\Utils\Sanitizer::password((string) ($body['nova_senha'] ?? $body['password'] ?? ''));

            if ($token === '') {
                return Response::json(['status' => 'error', 'message' => 'Token é obrigatório.'], 400);
            }
            if (trim($novaSenha) === '') {
                return Response::json(['status' => 'error', 'message' => 'Nova senha é obrigatória.'], 400);
            }

            $usuario = $this->authService->buscarPorTokenRecuperacaoSenha($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
            }

            $usuario->alterarSenha($novaSenha);
            $this->authService->salvar($usuario);
            $this->authService->limparTokenRecuperacaoSenha($usuario->getUuid()->toString());

            return Response::json(['status' => 'success', 'message' => 'Senha redefinida com sucesso.']);
        } catch (\Src\Modules\Usuario\Exceptions\InvalidPasswordException $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => 'Não foi possível redefinir a senha.',
                'details' => $this->debugAtivo() ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function validarTokenRecuperacao(Request $request, string $token): Response
    {
        try {
            $token = trim($token);
            if ($token === '') {
                return Response::json(['status' => 'error', 'message' => 'Token inválido.'], 400);
            }
            $usuario = $this->authService->buscarPorTokenRecuperacaoSenha($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
            }
            return Response::json(['status' => 'success']);
        } catch (\Throwable) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao validar token.'], 500);
        }
    }

    // ── Sessão ────────────────────────────────────────────────────────────

    public function me(Request $request): Response
    {
        $authUser = $request->attribute('auth_user');
        if (!$authUser) {
            return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
        }

        return Response::json([
            'status'  => 'success',
            'usuario' => [
                'uuid'          => $authUser->getUuid()->toString(),
                'nome_completo' => $authUser->getNomeCompleto(),
                'username'      => $authUser->getUsername(),
                'email'         => $authUser->getEmail(),
                'nivel_acesso'  => $authUser->getNivelAcesso(),
            ],
        ]);
    }

    public function refresh(Request $request): Response
    {
        $this->refreshTokenRepository->purgeExpired();
        $this->accessBlacklist->purgeExpired();

        $refreshToken = trim((string) ($request->body['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            return Response::json(['error' => 'Refresh token não fornecido.'], 400);
        }

        try {
            [$payload, $assinadoComApiSecret] = $this->authService->decodificarRefreshComSecret($refreshToken);
            $this->authService->validarRefreshNaoRevogado($payload, $refreshToken, $assinadoComApiSecret);

            $usuario = $this->authService->buscarPorUuid($payload->sub ?? '');
            if (!$usuario) {
                return Response::json(['error' => 'Usuário não encontrado.'], 404);
            }

            $this->authService->revogarRefreshPorJti($payload->jti ?? '');
            $tokens = $usuario->getNivelAcesso() === 'admin_system'
                ? $this->authService->emitirTokensAdmin($usuario)
                : $this->authService->emitirTokens($usuario);

            return Response::json([
                'access_token'       => $tokens['access_token'],
                'token_type'         => 'Bearer',
                'expires_in'         => $tokens['access_expira_em'],
                'refresh_token'      => $tokens['refresh_token'],
                'refresh_expires_in' => $tokens['refresh_expira_em'],
            ]);
        } catch (DomainException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode() ?: 401);
        } catch (\Throwable) {
            return Response::json(['error' => 'Erro ao renovar token.'], 500);
        }
    }

    public function logout(Request $request): Response
    {
        $token    = $request->bearerToken() ?? $this->tokenDoCookie();
        $userUuid = null;

        try {
            $this->refreshTokenRepository->purgeExpired();
            $this->accessBlacklist->purgeExpired();

            if ($token !== null) {
                [$payload] = JwtDecoder::decodeUser($token);
                $userUuid  = $payload->sub ?? null;
                $jti       = $payload->jti ?? '';
                if ($jti !== '') {
                    $exp = $payload->exp ?? time();
                    $this->accessBlacklist->revoke(
                        $jti,
                        $payload->sub ?? '',
                        (new \DateTimeImmutable())->setTimestamp($exp)
                    );
                }
                $this->authService->revogarRefreshPorUsuario($payload->sub ?? '');
            }
        } catch (\Throwable) {
            // melhor-esforço — logout sempre sucede
        }

        $this->auditLogger->registrar('auth.logout', $userUuid);
        $this->definirCookieAuth('', time() - 3600);
        return Response::json(['status' => 'success', 'message' => 'Logout realizado com sucesso.']);
    }

    // ── Verificação de e-mail ─────────────────────────────────────────────

    public function verifyEmail(Request $request): Response
    {
        $token = trim((string) ($request->query['token'] ?? $request->body['token'] ?? ''));

        if ($token === '') {
            return Response::json([
                'status'  => 'error',
                'message' => 'Token de verificação não informado. Informe via URL (?token=...) ou no corpo da requisição.',
            ], 400);
        }

        try {
            $usuario = $this->authService->buscarPorTokenVerificacaoEmail($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
            }
            if ($usuario->isEmailVerificado()) {
                return Response::json(['status' => 'success', 'message' => 'E-mail já verificado.']);
            }

            $this->authService->marcarEmailComoVerificado($usuario->getUuid()->toString());
            return Response::json(['status' => 'success', 'message' => 'E-mail verificado com sucesso.']);
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable) {
            return Response::json(['status' => 'error', 'message' => 'Falha ao verificar e-mail.'], 500);
        }
    }

    public function emailVerificationPolicy(Request $request): Response
    {
        $status       = 200;
        $responseData = ['status' => 'success'];

        try {
            if ($request->getMethod() === 'GET') {
                return Response::json([
                    'status'               => 'success',
                    'require_verification' => $this->carregarPoliticaVerificacaoEmail(),
                ]);
            }

            if ($request->getMethod() !== 'POST') {
                throw new DomainException('Método não suportado.', 405);
            }

            $body    = $request->body;
            $enabled = filter_var(
                $body['require_verification'] ?? $body['enabled'] ?? $body['value'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            $targetUser = $this->resolverUsuarioAlvo($body);
            if ($targetUser) {
                $verified = filter_var($body['verified'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $this->authService->marcarEmailComoVerificado($targetUser->getUuid()->toString(), $verified);
                $responseData['message'] = $verified ? 'E-mail verificado com sucesso.' : 'Verificação de e-mail removida.';
            } else {
                $this->atualizarPoliticaGlobal($enabled);
                $responseData['message']              = 'Policy global ' . ($enabled ? 'ativada.' : 'desativada.');
                $responseData['require_verification'] = $enabled;
            }
        } catch (DomainException $e) {
            $status       = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            $responseData = ['status' => 'error', 'message' => $e->getMessage()];
        } catch (\Throwable) {
            $status       = 500;
            $responseData = ['status' => 'error', 'message' => 'Falha ao processar política de e-mail.'];
        }

        return Response::json($responseData, $status);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function resolverUsuarioAlvo(array $body): ?\Src\Modules\Usuario\Entities\Usuario
    {
        if (!empty($body['user_id'])) {
            return $this->authService->buscarPorUuid($body['user_id']);
        }
        if (!empty($body['email'])) {
            return $this->authService->buscarPorEmail($body['email']);
        }
        return null;
    }

    private function atualizarPoliticaGlobal(bool $enabled): void
    {
        $caminho   = $this->caminhoPoliticaVerificacaoEmail();
        $diretorio = dirname($caminho);
        if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true) && !is_dir($diretorio)) {
            throw new DomainException('Não foi possível criar diretório de política.');
        }
        $payload = json_encode(['require_verification' => $enabled], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Escrita atômica: grava em arquivo temporário e renomeia (operação atômica no SO)
        $tmp = $caminho . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new DomainException('Não foi possível salvar a política de verificação.');
        }
        if (!rename($tmp, $caminho)) {
            @unlink($tmp);
            throw new DomainException('Não foi possível salvar a política de verificação.');
        }
    }

    private function definirCookieAuth(string $token, int $expiraEm): void
    {
        setcookie('auth_token', $token, \Src\Kernel\Support\CookieConfig::options($expiraEm));
    }

    private function tokenDoCookie(): ?string
    {
        $token = trim($_COOKIE['auth_token'] ?? '');
        return $token !== '' ? $token : null;
    }

    private function caminhoPoliticaVerificacaoEmail(): string
    {
        return dirname(__DIR__, 4) . '/storage/auth_policy.json';
    }

    private function carregarPoliticaVerificacaoEmail(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $caminho = $this->caminhoPoliticaVerificacaoEmail();
        if (!is_file($caminho) || !is_readable($caminho)) {
            return $cached = false;
        }
        $json = file_get_contents($caminho);
        if ($json === false) {
            return $cached = false;
        }
        $dados = json_decode($json, true);
        return $cached = is_array($dados) && (bool) ($dados['require_verification'] ?? $dados['enabled'] ?? false);
    }

    private function montarLinkRecuperacaoSenha(string $token): string
    {
        return \Src\Kernel\Support\UrlHelper::to('recuperar-senha?token=' . urlencode($token));
    }

    private function emailModuleEnabled(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $storage = dirname(__DIR__, 4) . '/storage';

        $capFile = $storage . '/capabilities_registry.json';
        if (is_file($capFile) && is_readable($capFile)) {
            $raw  = file_get_contents($capFile);
            $caps = ($raw !== false) ? (json_decode($raw, true) ?: []) : [];
            return $cached = !empty($caps['email-sender']);
        }

        $stateFile = $storage . '/modules_state.json';
        if (is_file($stateFile) && is_readable($stateFile)) {
            $raw   = file_get_contents($stateFile);
            $state = ($raw !== false) ? (json_decode($raw, true) ?: []) : [];
            return $cached = !empty($state['Email']) || !empty($state['vupi.us-module-email']) || !empty($state['module-email']);
        }

        return $cached = false;
    }

    private function debugAtivo(): bool
    {
        return $this->debug;
    }

    /**
     * Garante tempo mínimo de resposta para mitigar timing attacks.
     */
    private function enforceMinResponseTime(float $startTime, int $minMs = 200): void
    {
        $elapsed   = (microtime(true) - $startTime) * 1000;
        $remaining = $minMs - $elapsed;
        if ($remaining > 0) {
            usleep((int) ($remaining * 1000));
        }
    }
}
