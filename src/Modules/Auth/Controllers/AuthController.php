<?php

namespace Src\Modules\Auth\Controllers;

use DomainException;
use PDO;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\AuditLogger;
use Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository;
use Src\Modules\Auth\Repositories\RefreshTokenRepository;
use Src\Modules\Auth\Services\AuthService;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Contracts\EmailSenderInterface;

class AuthController
{
    private ?AuthService $authService = null;
    private ?UserRepositoryInterface $usuarioRepository = null;
    private ?RefreshTokenRepository $refreshTokenRepository = null;
    private ?AccessTokenBlacklistRepository $accessBlacklist = null;
    private ?PDO $pdo = null;
    private ?AuditLogger $auditLogger = null;

    public function __construct(
        private ?EmailSenderInterface $emailService
    ) {}

    private function audit(): AuditLogger
    {
        if ($this->auditLogger === null) {
            try {
                $this->auditLogger = new AuditLogger($this->pdo());
            } catch (\Throwable) {
                $this->auditLogger = new AuditLogger(null);
            }
        }
        return $this->auditLogger;
    }

    public function login(): Response
    {
        // /api/auth/login é exclusivo para admin_system — token assinado com JWT_API_SECRET
        $startTime = microtime(true);

        try {
            $this->refreshRepositorio()->purgeExpired();
            $this->blacklistRepositorio()->purgeExpired();

            $dados = $this->corpoDaRequisicao();
            $login = $dados['login'] ?? $dados['identifier'] ?? $dados['email'] ?? $dados['username'] ?? '';
            $senha = $dados['senha'] ?? $dados['password'] ?? '';

            $usuario = $this->servico()->autenticar((string)$login, (string)$senha);

            // Esta rota é exclusiva para admin_system
            if ($usuario->getNivelAcesso() !== 'admin_system') {
                $this->enforceMinResponseTime($startTime, 200);
                return Response::json(['status' => 'error', 'message' => 'Acesso restrito.'], 403);
            }

            if ($this->carregarPoliticaVerificacaoEmail() && !$usuario->isEmailVerificado()) {
                $this->enforceMinResponseTime($startTime, 200);
                return Response::json([
                    'status'             => 'error',
                    'message'            => 'Você precisa confirmar seu e-mail antes de fazer login. Verifique sua caixa de entrada ou solicite um novo link.',
                    'email_not_verified' => true,
                    'email'              => $usuario->getEmail(),
                ], 403);
            }

            // Token assinado com JWT_API_SECRET para admin_system
            $tokens = $this->servico()->emitirTokensAdmin($usuario);
            $this->definirCookieAuth($tokens['access_token'], $tokens['access_expira_em']);

            $this->audit()->registrar('auth.login.success', $usuario->getUuid()->toString(), [
                'username'     => $usuario->getUsername(),
                'nivel_acesso' => $usuario->getNivelAcesso(),
            ]);

            $this->enforceMinResponseTime($startTime, 200);
            return Response::json([
                'status'           => 'success',
                'access_token'     => $tokens['access_token'],
                'expires_in'       => $tokens['access_expira_em'],
                'refresh_token'    => $tokens['refresh_token'],
                'refresh_expires_in' => $tokens['refresh_expira_em'],
                'usuario' => [
                    'uuid'         => $usuario->getUuid()->toString(),
                    'nome_completo'=> $usuario->getNomeCompleto(),
                    'username'     => $usuario->getUsername(),
                    'email'        => $usuario->getEmail(),
                    'nivel_acesso' => $usuario->getNivelAcesso(),
                ]
            ]);
        } catch (DomainException $e) {
            $this->audit()->registrar('auth.login.failed', null, ['reason' => $e->getMessage()]);
            $this->enforceMinResponseTime($startTime, 200);
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            $this->enforceMinResponseTime($startTime, 200);
            error_log('[AuthController::login] ' . get_class($e) . ': ' . $e->getMessage());
            return Response::json(['status' => 'error', 'message' => 'Erro interno.'], 500);
        }
    }

    public function loginPublic(): Response
    {
        $startTime = microtime(true);

        try {
            $this->refreshRepositorio()->purgeExpired();
            $this->blacklistRepositorio()->purgeExpired();

            $dados = $this->corpoDaRequisicao();
            $login = $dados['login'] ?? $dados['identifier'] ?? $dados['email'] ?? $dados['username'] ?? '';
            $senha = $dados['senha'] ?? $dados['password'] ?? '';

            if (trim((string)$login) === '' || trim((string)$senha) === '') {
                $this->enforceMinResponseTime($startTime, 200);
                throw new DomainException('Login e senha são obrigatórios.', 400);
            }

            $usuario = $this->buscarUsuarioPorLogin((string)$login);
            if (!$usuario || !$usuario->verificarSenha((string)$senha)) {
                $this->audit()->registrar('auth.login.failed', null, ['identifier' => substr((string)$login, 0, 64)]);
                $this->enforceMinResponseTime($startTime, 200);
                throw new DomainException('Credenciais inválidas.', 401);
            }

            if (!$usuario->isAtivo()) {
                $this->enforceMinResponseTime($startTime, 200);
                throw new DomainException('Usuário desativado.', 403);
            }

            if ($this->carregarPoliticaVerificacaoEmail() && !$usuario->isEmailVerificado()) {
                $this->enforceMinResponseTime($startTime, 200);
                return Response::json([
                    'status'              => 'error',
                    'message'             => 'Você precisa confirmar seu e-mail antes de fazer login. Verifique sua caixa de entrada ou solicite um novo link.',
                    'email_not_verified'  => true,
                    'email'               => $usuario->getEmail(),
                ], 403);
            }

            // admin_system recebe token assinado com JWT_API_SECRET — obrigatório para rotas protegidas
            $tokens = $usuario->getNivelAcesso() === 'admin_system'
                ? $this->servico()->emitirTokensAdmin($usuario)
                : $this->servico()->emitirTokens($usuario);
            $this->definirCookieAuth($tokens['access_token'], $tokens['access_expira_em']);

            $this->audit()->registrar('auth.login.success', $usuario->getUuid()->toString(), [
                'username' => $usuario->getUsername(),
            ]);

            $this->enforceMinResponseTime($startTime, 200);
            return Response::json([
                'status' => 'success',
                'access_token' => $tokens['access_token'],
                'expires_in' => $tokens['access_expira_em'],
                'refresh_token' => $tokens['refresh_token'],
                'refresh_expires_in' => $tokens['refresh_expira_em'],
                'usuario' => [
                    'uuid' => $usuario->getUuid()->toString(),
                    'nome_completo' => $usuario->getNomeCompleto(),
                    'username' => $usuario->getUsername(),
                    'email' => $usuario->getEmail(),
                    'nivel_acesso' => $usuario->getNivelAcesso(),
                ]
            ]);
        } catch (DomainException $e) {
            $this->enforceMinResponseTime($startTime, 200);
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            // Garante delay mesmo em erros de banco/infra
            $this->enforceMinResponseTime($startTime, 200);
            error_log('[AuthController::loginPublic] ' . get_class($e) . ': ' . $e->getMessage());
            return Response::json([
                'status'  => 'error',
                'message' => 'Erro interno.',
            ], 500);
        }
    }

    public function solicitarRecuperacaoSenha(): Response
    {
        $startTime = microtime(true);
        try {
            $dados = $this->corpoDaRequisicao();
            $email = trim($dados['email'] ?? '');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->enforceMinResponseTime($startTime, 200);
                return Response::json([
                    'status' => 'error',
                    'message' => 'E-mail inválido ou não informado.'
                ], 400);
            }

            $usuario = $this->repositorio()->buscarPorEmail($email);

            // Só tenta enviar e-mail se o módulo estiver disponível E o usuário existir
            if ($usuario && $this->emailModuleEnabled() && $this->emailService !== null) {
                $token = bin2hex(random_bytes(32));
                $this->repositorio()->salvarTokenRecuperacaoSenha($usuario->getUuid()->toString(), $token);

                $canSend = $this->podeDispararEmailRecuperacao($email);
                $link = $this->montarLinkRecuperacaoSenha($token);

                if ($canSend) {
                    try {
                        $this->registrarDisparoEmailRecuperacao($email);
                        $this->mailer()->sendPasswordReset(
                            $usuario->getEmail(),
                            $usuario->getNomeCompleto(),
                            $link,
                            $_ENV['APP_LOGO_URL'] ?? null
                        );
                    } catch (\Throwable $mailError) {
                        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                            error_log('[AuthController] Falha ao enviar e-mail de recuperação: ' . $mailError->getMessage());
                        }
                    }
                }
            } elseif ($usuario && !$this->emailModuleEnabled()) {
                // Módulo de e-mail inativo: salva o token mas não envia
                $token = bin2hex(random_bytes(32));
                $this->repositorio()->salvarTokenRecuperacaoSenha($usuario->getUuid()->toString(), $token);
            }

            // Sempre retorna 200 com mensagem genérica para não revelar se o e-mail existe
            $this->enforceMinResponseTime($startTime, 200);
            return Response::json([
                'status' => 'success',
                'message' => 'Se o e-mail informado estiver cadastrado, um link de recuperação será enviado.'
            ]);
        } catch (\Throwable $e) {
            $this->enforceMinResponseTime($startTime, 200);
            return Response::json([
                'status' => 'error',
                'message' => 'Não foi possível iniciar a recuperação de senha.',
                'details' => $this->debugAtivo() ? $e->getMessage() : null
            ], 500);
        }
    }

    public function resetarSenha(): Response
    {
        try {
            $dados = $this->corpoDaRequisicao();
            $token = trim($dados['token'] ?? '');
            $novaSenha = (string)($dados['nova_senha'] ?? $dados['password'] ?? '');

            if ($token === '') {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Token é obrigatório.'
                ], 400);
            }

            if (strlen($novaSenha) < 8) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Nova senha deve ter ao menos 8 caracteres.'
                ], 400);
            }

            // Exige ao menos uma letra e um número
            if (!preg_match('/[A-Za-z]/', $novaSenha) || !preg_match('/[0-9]/', $novaSenha)) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Nova senha deve conter ao menos uma letra e um número.'
                ], 400);
            }

            $usuario = $this->repositorio()->buscarPorTokenRecuperacaoSenha($token);

            if (!$usuario) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Token inválido ou expirado.'
                ], 400);
            }

            $usuario->alterarSenha($novaSenha);
            $this->repositorio()->salvar($usuario);
            $this->repositorio()->limparTokenRecuperacaoSenha($usuario->getUuid()->toString());

            return Response::json([
                'status' => 'success',
                'message' => 'Senha redefinida com sucesso.'
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Não foi possível redefinir a senha.',
                'details' => $this->debugAtivo() ? $e->getMessage() : null
            ], 500);
        }
    }

    public function validarTokenRecuperacao($request, string $token): Response
    {
        try {
            $token = trim((string)$token);
            if ($token === '') {
                return Response::json(['status' => 'error', 'message' => 'Token inválido'], 400);
            }
            $usuario = $this->repositorio()->buscarPorTokenRecuperacaoSenha($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
            }
            return Response::json(['status' => 'success']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao validar token'], 500);
        }
    }

    public function me($request = null): Response
    {
        try {
            // Caminho correto: usuário injetado pelo AuthHybridMiddleware via $request->attribute()
            $authUser = null;
            if (is_object($request) && method_exists($request, 'attribute')) {
                $authUser = $request->attribute('auth_user');
            }

            if ($authUser) {
                return Response::json([
                    'status' => 'success',
                    'usuario' => [
                        'uuid' => $authUser->getUuid()->toString(),
                        'nome_completo' => $authUser->getNomeCompleto(),
                        'username' => $authUser->getUsername(),
                        'email' => $authUser->getEmail(),
                        'nivel_acesso' => $authUser->getNivelAcesso(),
                    ]
                ]);
            }

            // Fallback via token (compatibilidade)
            $token = $this->extrairTokenDeAutorizacao() ?? $this->tokenDoCookie();
            if ($token === null) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado'], 401);
            }
            $payload = $this->servico()->decodificarToken($token);
            $usuario = $this->repositorio()->buscarPorUuid($payload->sub ?? '');

            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado'], 404);
            }

            return Response::json([
                'status' => 'success',
                'usuario' => [
                    'uuid' => $usuario->getUuid()->toString(),
                    'nome_completo' => $usuario->getNomeCompleto(),
                    'username' => $usuario->getUsername(),
                    'email' => $usuario->getEmail(),
                    'nivel_acesso' => $usuario->getNivelAcesso(),
                ]
            ]);
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 401;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado'], 401);
        }
    }

    public function refresh(): Response
    {
        $this->refreshRepositorio()->purgeExpired();
        $this->blacklistRepositorio()->purgeExpired();

        $body = $this->corpoDaRequisicao();
        $refresh = $body['refresh_token'] ?? '';
        if ($refresh === '') {
            return Response::json(['erro' => 'Refresh token não fornecido'], 400);
        }

        try {
            $payload = $this->servico()->decodificarRefresh($refresh);
            $this->servico()->validarRefreshNaoRevogado($payload, $refresh);

            $usuario = $this->repositorio()->buscarPorUuid($payload->sub ?? '');
            if (!$usuario) {
                return Response::json(['erro' => 'Usuário não encontrado'], 404);
            }

            $this->servico()->revogarRefreshPorJti($payload->jti ?? '');
            // admin_system deve sempre receber token assinado com JWT_API_SECRET
            $tokens = $usuario->getNivelAcesso() === 'admin_system'
                ? $this->servico()->emitirTokensAdmin($usuario)
                : $this->servico()->emitirTokens($usuario);

            return Response::json([
                'access_token' => $tokens['access_token'],
                'token_type' => 'Bearer',
                'expires_in' => $tokens['access_expira_em'],
                'refresh_token' => $tokens['refresh_token'],
                'refresh_expires_in' => $tokens['refresh_expira_em']
            ]);
        } catch (DomainException $e) {
            return Response::json(['erro' => $e->getMessage()], $e->getCode() ?: 401);
        } catch (\Throwable $e) {
            return Response::json(['erro' => 'Erro ao renovar token'], 500);
        }
    }

    public function logout(): Response
    {
        $token = $this->extrairTokenDeAutorizacao() ?? $this->tokenDoCookie();
        $userUuid = null;
        try {
            $this->refreshRepositorio()->purgeExpired();
            $this->blacklistRepositorio()->purgeExpired();

            if ($token) {
                $payload = $this->servico()->decodificarToken($token);
                $userUuid = $payload->sub ?? null;
                $jti = $payload->jti ?? '';
                if ($jti !== '') {
                    $exp = $payload->exp ?? time();
                    $this->blacklistRepositorio()->revoke($jti, $payload->sub ?? '', (new \DateTimeImmutable())->setTimestamp($exp));
                }
                $this->servico()->revogarRefreshPorUsuario($payload->sub ?? '');
            }
        } catch (\Throwable $e) {
            // ignora falha de melhor-esforço
        }

        $this->audit()->registrar('auth.logout', $userUuid);
        $this->definirCookieAuth('', time() - 3600);
        return Response::json(['status' => 'success', 'message' => 'Logout realizado com sucesso.']);
    }

    public function verifyEmail(): Response
    {
        // Tenta pegar o token da URL (padrão) ou do corpo da requisição (caso alguém envie JSON)
        $token = $_GET['token'] ?? '';
        
        if (trim($token) === '') {
            $body = $this->corpoDaRequisicao();
            $token = $body['token'] ?? '';
        }

        if (trim($token) === '') {
            return Response::json([
                'status' => 'error', 
                'message' => 'Token de verificação não informado. Informe-o via URL (?token=...) ou no corpo da requisição.'
            ], 400);
        }

        try {
            $usuario = $this->repositorio()->buscarPorTokenVerificacaoEmail($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
            }
            
            // Verifica se já foi verificado anteriormente
            if ($usuario->isEmailVerificado()) {
                return Response::json(['status' => 'success', 'message' => 'O usuário já se encontra com o e-mail verificado.']);
            }

            $this->repositorio()->marcarEmailComoVerificado($usuario->getUuid()->toString());
            return Response::json(['status' => 'success', 'message' => 'E-mail verificado com sucesso.']);
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Falha ao verificar e-mail.'], 500);
        }
    }

    public function emailVerificationPolicy(): Response
    {
        $status = 200;
        $responseData = ['status' => 'success'];

        try {
            // GET — retorna a política atual
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $enabled = $this->carregarPoliticaVerificacaoEmail();
                return Response::json([
                    'status'               => 'success',
                    'require_verification' => $enabled,
                ]);
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new DomainException('Método não suportado.', 405);
            }

            $body    = $this->corpoDaRequisicao();
            $enabled = filter_var($body['require_verification'] ?? $body['enabled'] ?? $body['value'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $targetUser = $this->getTargetUser($body);
            if ($targetUser) {
                $responseData['message'] = $this->processUserVerification($targetUser, $body);
            } else {
                $this->updateGlobalPolicy($enabled);
                $responseData['message'] = 'Policy global de verificação de e-mail ' . ($enabled ? 'ativada.' : 'desativada.');
                $responseData['require_verification'] = $enabled;
            }
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            $responseData = ['status' => 'error', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $status = 500;
            $responseData = ['status' => 'error', 'message' => 'Falha ao processar política de e-mail.'];
        }

        return Response::json($responseData, $status);
    }

    private function getTargetUser(array $body)
    {
        if (!empty($body['user_id'])) {
            return $this->repositorio()->buscarPorUuid($body['user_id']);
        }
        if (!empty($body['email'])) {
            return $this->repositorio()->buscarPorEmail($body['email']);
        }
        return null;
    }

    private function processUserVerification($user, array $body): string
    {
        $verified = filter_var($body['verified'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->repositorio()->marcarEmailComoVerificado($user->getUuid()->toString(), $verified);
        return $verified ? 'E-mail verificado com sucesso.' : 'Verificação de e-mail removida.';
    }

    private function updateGlobalPolicy(bool $enabled): void
    {
        $caminho = $this->caminhoPoliticaVerificacaoEmail();
        $diretorio = dirname($caminho);
        if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true) && !is_dir($diretorio)) {
            throw new DomainException('Não foi possível criar diretório de política.');
        }
        $payload = json_encode(['require_verification' => $enabled], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($caminho, $payload) === false) {
            throw new DomainException('Não foi possível salvar a política de verificação.');
        }
    }

    private function servico(): AuthService
    {
        if ($this->authService === null) {
            $this->authService = new AuthService($this->repositorio(), $this->refreshRepositorio());
        }

        return $this->authService;
    }

    private function repositorio(): UserRepositoryInterface
    {
        if ($this->usuarioRepository === null) {
            // Usa a implementação concreta via contrato — sem importar o módulo Usuario diretamente
            $this->usuarioRepository = new \Src\Modules\Usuario\Repositories\UsuarioRepository($this->pdo());
        }

        return $this->usuarioRepository;
    }

    private function refreshRepositorio(): ?RefreshTokenRepository
    {
        if ($this->refreshTokenRepository === null) {
            $this->refreshTokenRepository = new RefreshTokenRepository($this->pdo());
        }

        return $this->refreshTokenRepository;
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dbType = $_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql';
        if ($dbType === 'postgresql') {
            $dbType = 'pgsql';
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $nome = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $usuario = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
        $senha = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
        $porta = $_ENV['DB_PORT'] ?? ($dbType === 'pgsql' ? '5432' : '3306');

        $dsn = $dbType === 'pgsql'
            ? "pgsql:host={$host};port={$porta};dbname={$nome}"
            : "mysql:host={$host};port={$porta};dbname={$nome}";

        $opcoes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ];

        $this->pdo = new PDO($dsn, $usuario, $senha, $opcoes);
        return $this->pdo;
    }

    private function blacklistRepositorio(): AccessTokenBlacklistRepository
    {
        if ($this->accessBlacklist === null) {
            $this->accessBlacklist = new AccessTokenBlacklistRepository($this->pdo());
        }

        return $this->accessBlacklist;
    }

    private function definirCookieAuth(string $token, int $expiraEm): void
    {
        $envSecure  = $this->boolEnv($_ENV['COOKIE_SECURE'] ?? getenv('COOKIE_SECURE') ?? 'false');
        $envSameSite = $this->resolverSameSite($_ENV['COOKIE_SAMESITE'] ?? getenv('COOKIE_SAMESITE') ?? 'Lax');
        $domain     = trim($_ENV['COOKIE_DOMAIN'] ?? getenv('COOKIE_DOMAIN') ?? '');
        // Remove protocolo caso COOKIE_DOMAIN tenha sido configurado com https:// ou http://
        $domain = preg_replace('#^https?://#', '', $domain);

        // Detecta HTTPS por qualquer uma das fontes disponíveis
        $appUrl  = $_ENV['APP_URL'] ?? getenv('APP_URL') ?? '';
        $isHttps = strncmp($appUrl, 'https://', 8) === 0                                          // APP_URL começa com https
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')                          // PHP nativo
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')                            // Nginx/proxy
            || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')                                 // Alguns proxies
            || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');                                    // Apache mod_rewrite

        $secure   = $envSecure && $isHttps;
        $sameSite = (!$secure && $envSameSite === 'None') ? 'Lax' : $envSameSite;

        $opcoes = [
            'expires'  => $expiraEm,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];

        if ($domain !== '') {
            $opcoes['domain'] = $domain;
        }

        setcookie('auth_token', $token, $opcoes);
    }

    private function corpoDaRequisicao(): array
    {
        $conteudoBruto = $GLOBALS['__raw_input'] ?? file_get_contents('php://input');
        if (!is_string($conteudoBruto)) {
            $conteudoBruto = '';
        }

        // Limita tamanho para evitar DoS por JSON profundo ou arrays gigantes
        if (strlen($conteudoBruto) > 64 * 1024) {
            return array_merge($_POST);
        }

        // Tenta decodificar JSON com profundidade limitada
        $dadosJson = json_decode($conteudoBruto, true, 8) ?? [];
        if (!is_array($dadosJson)) {
            $dadosJson = [];
        }

        // Fallback: tenta corrigir JSON levemente malformado (apenas se pequeno)
        if (empty($dadosJson) && strlen($conteudoBruto) < 4096 && trim($conteudoBruto) !== '') {
            $brutoCorrigido = preg_replace('/([\{,]\s*)([A-Za-z0-9_]+)\s*:/', '$1"$2":', $conteudoBruto) ?? $conteudoBruto;
            $brutoCorrigido = preg_replace('/:\s*([A-Za-z0-9_@.\-]+)(\s*[},])/', ':"$1"$2', $brutoCorrigido) ?? $brutoCorrigido;
            $tentativa = json_decode($brutoCorrigido, true, 8);
            if (is_array($tentativa)) {
                $dadosJson = $tentativa;
            }
        }

        // Fallback: form-urlencoded
        $dadosForm = [];
        if (empty($dadosJson) && strlen($conteudoBruto) < 4096 && trim($conteudoBruto) !== '') {
            parse_str($conteudoBruto, $dadosForm);
            if (!is_array($dadosForm)) {
                $dadosForm = [];
            }
        }

        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            error_log('[AuthController] raw length=' . strlen($conteudoBruto));
            error_log('[AuthController] content-type=' . htmlspecialchars($_SERVER['CONTENT_TYPE'] ?? '', ENT_QUOTES, 'UTF-8'));
        }

        // Sanitiza: garante que os valores do merge são escalares ou arrays simples
        $merged = array_merge($_POST, $dadosForm, $dadosJson);

        // Converte valores não-escalares para string vazia (evita TypeError em campos de texto)
        array_walk($merged, function (&$v) {
            if (!is_scalar($v) && $v !== null) {
                $v = '';
            }
        });

        return $merged;
    }

    private function tokenDoCookie(): ?string
    {
        $token = $_COOKIE['auth_token'] ?? '';
        $limpo = trim($token);
        return $limpo === '' ? null : $limpo;
    }

    private function extrairTokenDeAutorizacao(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $candidate = trim(substr($authHeader, 7));
            return $candidate !== '' ? $candidate : null;
        }
        return null;
    }

    private function boolEnv(string $valor): bool
    {
        $valorNormalizado = strtolower(trim($valor));
        return in_array($valorNormalizado, ['1', 'true', 'on', 'yes'], true);
    }

    private function resolverSameSite(string $valor): string
    {
        $normalizado = ucfirst(strtolower(trim($valor)));
        $permitidos = ['Lax', 'Strict', 'None'];
        return in_array($normalizado, $permitidos, true) ? $normalizado : 'Lax';
    }

    private function debugAtivo(): bool
    {
        return $this->boolEnv($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'false');
    }

    /**
     * Garante tempo mínimo de resposta para mitigar timing attacks.
     * @param float $startTime microtime(true) do início da requisição
     * @param int   $minMs     tempo mínimo em milissegundos
     */
    private function enforceMinResponseTime(float $startTime, int $minMs = 200): void
    {
        $elapsed = (microtime(true) - $startTime) * 1000;
        $remaining = $minMs - $elapsed;
        if ($remaining > 0) {
            usleep((int)($remaining * 1000));
        }
    }

    private function buscarUsuarioPorLogin(string $login)
    {
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return $this->repositorio()->buscarPorEmail($login);
        }
        return $this->repositorio()->buscarPorUsername($login);
    }

    private function caminhoPoliticaVerificacaoEmail(): string
    {
        return dirname(__DIR__, 4) . '/storage/auth_policy.json';
    }

    private function carregarPoliticaVerificacaoEmail(): bool
    {
        $caminho = $this->caminhoPoliticaVerificacaoEmail();
        if (!file_exists($caminho)) {
            return false;
        }
        $json = file_get_contents($caminho);
        if ($json === false) {
            return false;
        }
        $dados = json_decode($json, true);
        return is_array($dados) && (bool)($dados['require_verification'] ?? $dados['enabled'] ?? false);
    }

    private function podeDispararEmailRecuperacao(string $email): bool
    {
        try {
            $stmt = $this->pdo()->prepare(
                "SELECT sent_at FROM email_throttle WHERE type = 'password_reset' AND email = :email"
            );
            $stmt->execute([':email' => strtolower(trim($email))]);
            $row = $stmt->fetch();
            if (!$row) {
                return true;
            }
            return (time() - strtotime($row['sent_at'])) >= 120;
        } catch (\Throwable) {
            return true;
        }
    }

    private function registrarDisparoEmailRecuperacao(string $email): void
    {
        try {
            $pdo    = $this->pdo();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                $pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at) VALUES ('password_reset', :email, NOW())
                     ON CONFLICT (type, email) DO UPDATE SET sent_at = NOW()"
                )->execute([':email' => strtolower(trim($email))]);
                $pdo->exec("DELETE FROM email_throttle WHERE sent_at < NOW() - INTERVAL '3600 seconds'");
            } else {
                $pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at) VALUES ('password_reset', :email, NOW())
                     ON DUPLICATE KEY UPDATE sent_at = NOW()"
                )->execute([':email' => strtolower(trim($email))]);
                $pdo->exec("DELETE FROM email_throttle WHERE sent_at < DATE_SUB(NOW(), INTERVAL 3600 SECOND)");
            }
        } catch (\Throwable $e) {
            error_log('[AuthController] throttle record failed: ' . $e->getMessage());
        }
    }

    private function mailer(): ?EmailSenderInterface
    {
        return $this->emailService;
    }

    private function emailModuleEnabled(): bool
    {
        // 1. Check if Email module is installed and enabled via ModuleLoader/PluginManager state
        $storage = dirname(__DIR__, 4) . '/storage';
        
        // Use capabilities registry to check if 'email-sender' has an active provider
        $capFile = $storage . '/capabilities_registry.json';
        if (file_exists($capFile)) {
            $caps = json_decode(file_get_contents($capFile), true) ?: [];
            if (empty($caps['email-sender'])) {
                return false;
            }
        } else {
            // Fallback: Check modules_state.json directly if registry is missing
            $stateFile = $storage . '/modules_state.json';
            if (file_exists($stateFile)) {
                $state = json_decode(file_get_contents($stateFile), true) ?: [];
                // Check common names for email module
                if (empty($state['Email']) && empty($state['sweflow-module-email']) && empty($state['module-email'])) {
                    return false;
                }
            } else {
                // If no state file exists, assume minimal install without email
                return false;
            }
        }
        
        return true;
    }

    private function montarLinkRecuperacaoSenha(string $token): string
    {
        $base = rtrim($_ENV['APP_URL_FRONTEND'] ?? $_ENV['APP_URL'] ?? '', '/');
        if ($base === '') {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = $scheme . '://' . $host;
        }

        return $base . '/recuperar-senha?token=' . urlencode($token);
    }
}
