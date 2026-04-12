<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\LinkEncurtador\Repositories\LinkUsuarioRepository;

/**
 * Auth exclusiva do encurtador de links.
 * Completamente separada do Auth do kernel.
 * Usuários criados aqui NÃO têm acesso à IDE ou ao dashboard da vupi.us API.
 */
final class AuthController
{
    private const SESSION_TTL    = 2592000; // 30 dias
    private const COOKIE_NAME    = 'link_session';
    private const MIN_PASS_LEN   = 8;

    public function __construct(
        private readonly LinkUsuarioRepository $repo,
    ) {}

    // ── POST /api/link-auth/register ──────────────────────────────────────
    public function register(Request $request): Response
    {
        $nome  = trim((string) ($request->body['nome']  ?? ''));
        $email = trim((string) ($request->body['email'] ?? ''));
        $senha = (string) ($request->body['senha'] ?? '');

        if ($nome === '' || $email === '' || $senha === '') {
            return Response::json(['error' => 'Nome, e-mail e senha são obrigatórios.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'E-mail inválido.'], 422);
        }
        if (strlen($senha) < self::MIN_PASS_LEN) {
            return Response::json(['error' => 'A senha deve ter pelo menos ' . self::MIN_PASS_LEN . ' caracteres.'], 422);
        }
        if ($this->repo->emailExists($email)) {
            return Response::json(['error' => 'Este e-mail já está cadastrado.'], 409);
        }

        $user  = $this->repo->create([
            'nome'       => $nome,
            'email'      => $email,
            'senha_hash' => password_hash($senha, PASSWORD_ARGON2ID),
        ]);
        $token = $this->repo->createSession($user['id'], self::SESSION_TTL);

        return $this->sessionResponse($user, $token, 201);
    }

    // ── POST /api/link-auth/login ─────────────────────────────────────────
    public function login(Request $request): Response
    {
        $email = trim((string) ($request->body['email'] ?? ''));
        $senha = (string) ($request->body['senha'] ?? '');

        if ($email === '' || $senha === '') {
            return Response::json(['error' => 'E-mail e senha são obrigatórios.'], 422);
        }

        $user = $this->repo->findByEmail($email);
        if ($user === null || $user['senha_hash'] === '' || !password_verify($senha, $user['senha_hash'])) {
            return Response::json(['error' => 'E-mail ou senha incorretos.'], 401);
        }

        $token = $this->repo->createSession($user['id'], self::SESSION_TTL);
        return $this->sessionResponse($user, $token);
    }

    // ── POST /api/link-auth/google ────────────────────────────────────────
    public function googleAuth(Request $request): Response
    {
        $idToken = trim((string) ($request->body['id_token'] ?? ''));
        if ($idToken === '') {
            return Response::json(['error' => 'id_token é obrigatório.'], 422);
        }

        // Verifica o token com a API do Google
        $googleUser = $this->verifyGoogleToken($idToken);
        if ($googleUser === null) {
            return Response::json(['error' => 'Token Google inválido ou expirado.'], 401);
        }

        $googleId  = $googleUser['sub'];
        $email     = $googleUser['email'] ?? '';
        $nome      = $googleUser['name']  ?? '';
        $avatarUrl = $googleUser['picture'] ?? '';

        if ($email === '') {
            return Response::json(['error' => 'Não foi possível obter o e-mail da conta Google.'], 422);
        }

        // Busca por google_id primeiro, depois por email
        $user = $this->repo->findByGoogleId($googleId);
        if ($user === null) {
            $user = $this->repo->findByEmail($email);
            if ($user !== null) {
                // Vincula google_id à conta existente
                $this->repo->update($user['id'], ['google_id' => $googleId, 'avatar_url' => $avatarUrl]);
                $user = $this->repo->findById($user['id']);
            } else {
                // Cria nova conta via Google (sem senha)
                $user = $this->repo->create([
                    'nome'       => $nome,
                    'email'      => $email,
                    'senha_hash' => '', // sem senha — login apenas via Google
                    'google_id'  => $googleId,
                    'avatar_url' => $avatarUrl,
                ]);
            }
        }

        if ($user === null) {
            return Response::json(['error' => 'Erro ao criar conta.'], 500);
        }

        $token = $this->repo->createSession($user['id'], self::SESSION_TTL);
        return $this->sessionResponse($user, $token);
    }

    // ── GET /api/link-auth/me ─────────────────────────────────────────────
    public function me(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }
        return Response::json(['user' => $this->publicUser($user)]);
    }

    // ── PUT /api/link-auth/profile ────────────────────────────────────────
    public function updateProfile(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) return Response::json(['error' => 'Não autenticado.'], 401);

        $data = [];
        if (isset($request->body['nome']))       $data['nome']       = trim((string) $request->body['nome']);
        if (isset($request->body['avatar_url'])) $data['avatar_url'] = trim((string) $request->body['avatar_url']);

        if (!empty($data)) {
            $this->repo->update($user['id'], $data);
        }

        return Response::json(['user' => $this->publicUser($this->repo->findById($user['id']) ?? $user)]);
    }

    // ── PUT /api/link-auth/password ───────────────────────────────────────
    public function changePassword(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) return Response::json(['error' => 'Não autenticado.'], 401);

        $atual   = (string) ($request->body['senha_atual'] ?? '');
        $nova    = (string) ($request->body['nova_senha']  ?? '');
        $confirm = (string) ($request->body['confirmar']   ?? '');

        if ($atual === '' || $nova === '' || $confirm === '') {
            return Response::json(['error' => 'Preencha todos os campos.'], 422);
        }
        if ($nova !== $confirm) {
            return Response::json(['error' => 'As senhas não coincidem.'], 422);
        }
        if (strlen($nova) < self::MIN_PASS_LEN) {
            return Response::json(['error' => 'A nova senha deve ter pelo menos ' . self::MIN_PASS_LEN . ' caracteres.'], 422);
        }
        if ($user['senha_hash'] === '' || !password_verify($atual, $user['senha_hash'])) {
            return Response::json(['error' => 'Senha atual incorreta.'], 401);
        }

        $this->repo->update($user['id'], ['senha_hash' => password_hash($nova, PASSWORD_ARGON2ID)]);
        return Response::json(['updated' => true]);
    }

    // ── POST /api/link-auth/logout ────────────────────────────────────────
    public function logout(Request $request): Response
    {
        $token = $this->extractToken($request);
        if ($token !== '') {
            $this->repo->revokeSession($token);
        }
        return Response::json(['logged_out' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function resolveUser(Request $request): ?array
    {
        $token = $this->extractToken($request);
        if ($token === '') return null;
        return $this->repo->validateSession($token);
    }

    private function extractToken(Request $request): string
    {
        // 1. Header Authorization: Bearer <token>
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        // 2. Cookie link_session
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (is_string($cookie) && $cookie !== '') {
            return trim($cookie);
        }
        // 3. Body token (para requests JSON)
        $bodyToken = $request->body['link_token'] ?? '';
        if (is_string($bodyToken) && $bodyToken !== '') {
            return trim($bodyToken);
        }
        return '';
    }

    private function sessionResponse(array $user, string $token, int $status = 200): Response
    {
        return Response::json([
            'token' => $token,
            'user'  => $this->publicUser($user),
        ], $status);
    }

    private function publicUser(array $user): array
    {
        return [
            'id'         => $user['id'],
            'nome'       => $user['nome'],
            'email'      => $user['email'],
            'avatar_url' => $user['avatar_url'] ?? '',
            'google'     => !empty($user['google_id']),
        ];
    }

    /**
     * Verifica o id_token do Google via tokeninfo endpoint.
     * Em produção, use a biblioteca google/apiclient ou verifique o JWT localmente.
     */
    private function verifyGoogleToken(string $idToken): ?array
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
        if ($clientId === '') {
            return null; // Google OAuth não configurado
        }

        $url  = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $ctx  = stream_context_create(['http' => ['timeout' => 5]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return null;

        $data = json_decode($resp, true);
        if (!is_array($data)) return null;

        // Valida audience
        if (($data['aud'] ?? '') !== $clientId) return null;
        // Valida expiração
        if ((int)($data['exp'] ?? 0) < time()) return null;
        // Valida email verificado
        if (($data['email_verified'] ?? 'false') !== 'true') return null;

        return $data;
    }
}
