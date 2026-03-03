<?php

namespace Src\Modules\Auth\Controllers;

use DomainException;
use PDO;
use Src\Http\Response\Response;
use Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository;
use Src\Modules\Auth\Repositories\RefreshTokenRepository;
use Src\Modules\Auth\Services\AuthService;
use Src\Modules\Usuario\Repositories\UsuarioRepository;

class AuthController
{
    private ?AuthService $authService = null;
    private ?UsuarioRepository $usuarioRepository = null;
    private ?RefreshTokenRepository $refreshTokenRepository = null;
    private ?AccessTokenBlacklistRepository $accessBlacklist = null;
    private ?PDO $pdo = null;

    public function login(): Response
    {
        try {
            // Limpa tokens expirados (melhor-esforço)
            $this->refreshRepositorio()->purgeExpired();
            $this->blacklistRepositorio()->purgeExpired();

            $dados = $this->corpoDaRequisicao();
            $login = $dados['login'] ?? $dados['email'] ?? $dados['username'] ?? '';
            $senha = $dados['senha'] ?? $dados['password'] ?? '';

            $usuario = $this->servico()->autenticar($login, $senha);
            if ($this->carregarPoliticaVerificacaoEmail() && !$usuario->isEmailVerificado()) {
                throw new DomainException('Você precisa confirmar seu e-mail antes de fazer login.', 403);
            }
            $tokens = $this->servico()->emitirTokens($usuario);
            $this->definirCookieAuth($tokens['access_token'], $tokens['access_expira_em']);

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
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $status);
        } catch (\Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro interno ao autenticar',
                'details' => $this->debugAtivo() ? $e->getMessage() : null
            ], 500);
        }
    }

    public function loginPublic(): Response
    {
        try {
            $this->refreshRepositorio()->purgeExpired();
            $this->blacklistRepositorio()->purgeExpired();

            $dados = $this->corpoDaRequisicao();
            $login = $dados['login'] ?? $dados['email'] ?? $dados['username'] ?? '';
            $senha = $dados['senha'] ?? $dados['password'] ?? '';

            if (trim($login) === '' || trim($senha) === '') {
                throw new DomainException('Login e senha são obrigatórios.', 400);
            }

            $usuario = $this->buscarUsuarioPorLogin($login);
            if (!$usuario || !$usuario->verificarSenha($senha)) {
                throw new DomainException('Credenciais inválidas.', 401);
            }

            if (!$usuario->isAtivo()) {
                throw new DomainException('Usuário desativado.', 403);
            }

            if ($this->carregarPoliticaVerificacaoEmail() && !$usuario->isEmailVerificado()) {
                throw new DomainException('Você precisa confirmar seu e-mail antes de fazer login.', 403);
            }

            $tokens = $this->servico()->emitirTokens($usuario);
            $this->definirCookieAuth($tokens['access_token'], $tokens['access_expira_em']);

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
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $status);
        } catch (\Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro interno ao autenticar',
                'details' => $this->debugAtivo() ? $e->getMessage() : null
            ], 500);
        }
    }

    public function me(): Response
    {
        try {
            $payload = $GLOBALS['__auth_payload'] ?? null;
            if ($payload) {
                $usuario = $this->repositorio()->buscarPorUuid($payload->sub ?? '');
            } else {
                $token = $this->tokenDoCookie();
                if ($token === null) {
                    return Response::json([
                        'status' => 'error',
                        'message' => 'Não autenticado'
                    ], 401);
                }
                $payload = $this->servico()->decodificarToken($token);
                $payload->tipo = $payload->tipo ?? 'user';
                $payload->iss = $payload->iss ?? ($_ENV['JWT_ISSUER'] ?? getenv('JWT_ISSUER') ?? null);
                $payload->aud = $payload->aud ?? ($_ENV['JWT_AUDIENCE'] ?? getenv('JWT_AUDIENCE') ?? null);
                $usuario = $this->repositorio()->buscarPorUuid($payload->sub ?? '');
            }

            if (!$usuario) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Usuário não encontrado'
                ], 404);
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
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $status);
        } catch (\Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Token inválido ou expirado'
            ], 401);
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
            $tokens = $this->servico()->emitirTokens($usuario);

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
        try {
            $this->refreshRepositorio()->purgeExpired();
            $this->blacklistRepositorio()->purgeExpired();

            if ($token) {
                $payload = $this->servico()->decodificarToken($token);
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

        $this->definirCookieAuth('', time() - 3600);
        return Response::json([
            'status' => 'success',
            'message' => 'Logout realizado com sucesso.'
        ]);
    }

    public function verifyEmail(): Response
    {
        $token = $_GET['token'] ?? '';
        if (trim($token) === '') {
            return Response::json(['status' => 'error', 'message' => 'Token não informado.'], 400);
        }
        try {
            $usuario = $this->repositorio()->buscarPorTokenVerificacaoEmail($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
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
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = $this->corpoDaRequisicao();
                $enabled = filter_var($body['require_verification'] ?? $body['enabled'] ?? $body['value'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $this->salvarPoliticaVerificacaoEmail($enabled);
                return Response::json(['require_verification' => $enabled]);
            }

            $estado = $this->carregarPoliticaVerificacaoEmail();
            return Response::json(['require_verification' => $estado]);
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
            return Response::json(['error' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Falha ao processar política de verificação de e-mail'], 500);
        }
    }

    private function servico(): AuthService
    {
        if ($this->authService === null) {
            $this->authService = new AuthService($this->repositorio(), $this->refreshRepositorio());
        }

        return $this->authService;
    }

    private function repositorio(): UsuarioRepository
    {
        if ($this->usuarioRepository === null) {
            $this->usuarioRepository = new UsuarioRepository($this->pdo());
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
        $secure = $this->boolEnv($_ENV['COOKIE_SECURE'] ?? getenv('COOKIE_SECURE') ?? 'false');
        $sameSite = $this->resolverSameSite($_ENV['COOKIE_SAMESITE'] ?? getenv('COOKIE_SAMESITE') ?? 'Lax');
        $domain = trim($_ENV['COOKIE_DOMAIN'] ?? getenv('COOKIE_DOMAIN') ?? '');

        $opcoes = [
            'expires' => $expiraEm,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ];

        if ($domain !== '') {
            $opcoes['domain'] = $domain;
        }

        setcookie('auth_token', $token, $opcoes);
    }

    private function corpoDaRequisicao(): array
    {
        $conteudoBruto = $GLOBALS['__raw_input'] ?? file_get_contents('php://input');

        $dadosJson = json_decode($conteudoBruto, true) ?? [];
        if (!is_array($dadosJson)) {
            $dadosJson = [];
        }

        if (empty($dadosJson) && is_string($conteudoBruto) && trim($conteudoBruto) !== '') {
            $brutoCorrigido = $conteudoBruto;
            $brutoCorrigido = preg_replace('/([\{,]\s*)([A-Za-z0-9_]+)\s*:/', '$1"$2":', $brutoCorrigido);
            $brutoCorrigido = preg_replace('/:\s*([A-Za-z0-9_@.\-]+)(\s*[},])/', ':"$1"$2', $brutoCorrigido);
            $dadosJson = json_decode($brutoCorrigido, true) ?? [];
            if (!is_array($dadosJson)) {
                $dadosJson = [];
            }
        }

        error_log('[AuthController] raw length=' . strlen((string)$conteudoBruto) . ' content=' . substr((string)$conteudoBruto, 0, 200));
        error_log('[AuthController] _POST=' . json_encode($_POST));
        error_log('[AuthController] content-type=' . ($_SERVER['CONTENT_TYPE'] ?? ''));

        $dadosForm = [];
        if (empty($dadosJson) && is_string($conteudoBruto) && trim($conteudoBruto) !== '') {
            parse_str($conteudoBruto, $dadosForm);
            if (!is_array($dadosForm)) {
                $dadosForm = [];
            }
        }

        return array_merge($_POST, $dadosForm, $dadosJson);
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
        $json = @file_get_contents($caminho);
        if ($json === false) {
            return false;
        }
        $dados = json_decode($json, true);
        if (!is_array($dados)) {
            return false;
        }
        return (bool)($dados['require_verification'] ?? $dados['enabled'] ?? false);
    }

    private function salvarPoliticaVerificacaoEmail(bool $enabled): void
    {
        $caminho = $this->caminhoPoliticaVerificacaoEmail();
        $diretorio = dirname($caminho);
        if (!is_dir($diretorio)) {
            if (!@mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
                throw new DomainException('Não foi possível criar diretório de política.');
            }
        }
        $payload = ['require_verification' => $enabled];
        $gravado = @file_put_contents($caminho, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($gravado === false) {
            throw new DomainException('Não foi possível salvar a política de verificação.');
        }
    }
}
