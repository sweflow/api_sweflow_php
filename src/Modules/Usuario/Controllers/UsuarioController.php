<?php

namespace Src\Modules\Usuario\Controllers;

use Src\Modules\Usuario\Services\UsuarioServiceInterface;
use Src\Modules\Usuario\Entities\Usuario;
use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Contracts\EmailSenderInterface;
use Src\Kernel\Utils\ImageProcessor;
use DomainException;
use Throwable;


class UsuarioController
{
    public function __construct(
        private UsuarioServiceInterface $service,
        private ?EmailSenderInterface $emailService
    ) {}
    
    /**
     * POST /criar/usuario
     */
    public function criar($request): Response
    {
        try {
            $data = $request->body ?? [];
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                error_log('DEBUG UsuarioController->criar $request->body: ' . print_r($data, true));
            }
            // Validação de campos obrigatórios
            foreach (['nome_completo', 'username', 'email', 'senha'] as $campo) {
                if (empty($data[$campo])) {
                    return Response::json([
                        'status' => 'error',
                        'message' => "Campo obrigatório não informado: $campo"
                    ], 400);
                }
            }
            // Validação de e-mail
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'E-mail inválido.'
                ], 400);
            }
            // Validação de senha mínima
            if (strlen((string)$data['senha']) < 8) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'A senha deve ter no mínimo 8 caracteres.'
                ], 400);
            }
            // Validação de unicidade antes de criar o objeto
            if ($this->service->emailExiste($data['email'])) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'E-mail já cadastrado.'
                ], 400);
            }
            if ($this->service->usernameExiste($data['username'])) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Username já cadastrado.'
                ], 400);
            }

            $usuario = Usuario::registrar(
                $data['nome_completo'],
                $data['username'],
                $data['email'],
                $data['senha'],
                $data['url_avatar'] ?? null,
                $data['url_capa'] ?? null,
                $data['biografia'] ?? null,
                'usuario', // nivel_acesso sempre 'usuario' no registro público — nunca aceitar do cliente
                false,
                'Não verificado'
            );
            $this->service->criar($usuario);

            // Tenta enviar e-mail se o serviço estiver disponível
            if ($this->emailService && $this->politicaVerificacaoAtiva()) {
                $token = bin2hex(random_bytes(32));
                $this->service->salvarTokenVerificacaoEmail($usuario->getUuid()->toString(), $token);
                $link = $this->montarLinkVerificacao($token);
                $this->emailService->sendConfirmation($usuario->getEmail(), $usuario->getNomeCompleto(), $link, $_ENV['APP_LOGO_URL'] ?? null);
            }

            return Response::json([
                'status' => 'success',
                'uuid' => $usuario->getUuid()->toString(),
                'message' => 'Usuário criado com sucesso.'
            ], 201);

        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);

        } catch (Throwable $e) {
            $debug = getenv('APP_DEBUG') ?: ($_ENV['APP_DEBUG'] ?? 'false');
            $details = ($debug === 'true') ? $e->getMessage() : 'Erro interno no servidor';
            return Response::json([
                'status' => 'error',
                'message' => 'Erro interno no servidor',
                'details' => $details
            ], 500);
        }
    }

    /**
     * GET /usuarios
     */
    public function listar($request, int $pagina = 1, int $porPagina = 10): Response
    {
        $usuarios = $this->service->listar($pagina, $porPagina);
        $data = array_map(fn($u) => [
            'uuid' => $u->getUuid()->toString(),
            'nome_completo' => $u->getNomeCompleto(),
            'username' => $u->getUsername(),
            'email' => $u->getEmail(),
            'ativo' => $u->isAtivo(),
            'nivel_acesso' => $u->getNivelAcesso(),
            'criado_em' => $u->getCriadoEm()->format('c'),
            'url_avatar' => $u->getUrlAvatar(),
            'url_capa' => $u->getUrlCapa(),
            'biografia' => $u->getBiografia(),
            'status_verificacao' => $u->getStatusVerificacao(),
        ], $usuarios);
        return Response::json([
            'status' => 'success',
            'usuarios' => $data
        ]);
    // ...continua normalmente, sem fechamento extra aqui
    }

    /**
     * PUT /usuario/{uuid}
     */
    public function atualizar($request, string $uuid): Response
    {
        try {
            $data = $request->body ?? [];

            // Apenas admin_system pode alterar nivel_acesso
            $authUser = $request->attribute('auth_user');
            $userRole = ($authUser && method_exists($authUser, 'getNivelAcesso')) ? $authUser->getNivelAcesso() : '';
            if (isset($data['nivel_acesso']) && $userRole !== 'admin_system') {
                unset($data['nivel_acesso']);
            }

            $this->service->atualizar($uuid, $data);
            return Response::json([
                'status' => 'success',
                'message' => 'Usuário atualizado com sucesso'
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode() === 500 ? 500 : 400);
        } catch (Throwable $e) {
            $debug = (($_ENV['APP_DEBUG'] ?? 'false') === 'true');
            return Response::json([
                'status' => 'error',
                'message' => 'Erro interno no servidor',
                'details' => $debug ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * PUT /api/perfil/senha
     * Altera a senha do usuário autenticado
     */
    public function alterarSenha($request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado'], 401);
            }

            $data = $request->body ?? [];
            $senhaAtual = $data['senha_atual'] ?? null;
            $novaSenha = $data['nova_senha'] ?? null;
            $logoutAll = $data['logout_all'] ?? false;

            if (!$senhaAtual || !$novaSenha) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Senha atual e nova senha são obrigatórias.'
                ], 400);
            }

            // Verifica se a senha atual está correta
            if (!$this->service->verificarSenha($authUser->getUuid()->toString(), $senhaAtual)) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Senha atual incorreta.'
                ], 400);
            }

            // Atualiza a senha
            $this->service->alterarSenha($authUser->getUuid()->toString(), $novaSenha, $logoutAll);

            return Response::json([
                'status' => 'success',
                'message' => 'Senha alterada com sucesso.'
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Erro interno no servidor',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/perfil
     * Atualiza o perfil do usuário autenticado
     */
    public function atualizarPerfil($request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado'], 401);
            }

            $data = $request->body ?? [];
            if (isset($data['avatar_url']) && !isset($data['url_avatar'])) {
                $data['url_avatar'] = $data['avatar_url'];
                unset($data['avatar_url']);
            }
            if (isset($data['cover_url']) && !isset($data['url_capa'])) {
                $data['url_capa'] = $data['cover_url'];
                unset($data['cover_url']);
            }
            // Campos que o usuário NÃO pode alterar no próprio perfil
            unset($data['nivel_acesso'], $data['ativo'], $data['email_verificado'], $data['status_verificacao']);
            $this->service->atualizar($authUser->getUuid()->toString(), $data);

            // Retornar usuário atualizado
            $updated = $this->service->buscarPorUuid($authUser->getUuid()->toString());
            if (!$updated) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado após atualização'], 500);
            }

            return Response::json([
                'status' => 'success',
                'message' => 'Perfil atualizado com sucesso',
                'usuario' => [
                    'uuid' => $updated->getUuid()->toString(),
                    'nome_completo' => $updated->getNomeCompleto(),
                    'username' => $updated->getUsername(),
                    'email' => $updated->getEmail(),
                    'ativo' => $updated->isAtivo(),
                    'criado_em' => $updated->getCriadoEm()->format('c'),
                    'url_avatar' => $updated->getUrlAvatar(),
                    'url_capa' => $updated->getUrlCapa(),
                    'biografia' => $updated->getBiografia(),
                    'status_verificacao' => $updated->getStatusVerificacao(),
                ]
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode() === 500 ? 500 : 400);
        } catch (Throwable $e) {
            $debug = (($_ENV['APP_DEBUG'] ?? 'false') === 'true');
            return Response::json([
                'status' => 'error',
                'message' => 'Erro interno no servidor',
                'details' => $debug ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * POST /api/perfil/upload
     * Recebe multipart/form-data: file (imagem) e type ('avatar'|'cover')
     * Retorna { status: 'success', url: '/assets/...' }
     */
    public function uploadProfileImage($request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado'], 401);
            }

            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                return Response::json(['status' => 'error', 'message' => 'Arquivo não fornecido'], 400);
            }

            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return Response::json(['status' => 'error', 'message' => 'Erro no upload do arquivo'], 400);
            }

            // Aceita qualquer imagem cujo tipo MIME comece com 'image/'
            $allowed = [
                'image/jpeg' => '.jpg',
                'image/jpg' => '.jpg',
                'image/png' => '.png',
                'image/webp' => '.webp',
                'image/svg+xml' => '.svg',
                'image/svg' => '.svg',
            ];

            // Use getimagesize como primeira tentativa
            $imgInfo = @getimagesize($file['tmp_name']);
            $mime = '';
            if (is_array($imgInfo) && isset($imgInfo['mime'])) {
                $mime = $imgInfo['mime'];
            } else {
                // SVG não é reconhecido por getimagesize, então verifica extensão e type
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext === 'svg' && (isset($file['type']) && ($file['type'] === 'image/svg+xml' || $file['type'] === 'image/svg'))) {
                    $mime = $file['type'];
                } else {
                    $mime = $file['type'] ?? '';
                }
            }

            // Se o tipo MIME começar com 'image/', aceita a extensão original
            if (str_starts_with($mime, 'image/')) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!isset($allowed[$mime])) {
                    $allowed[$mime] = '.' . $ext;
                }
            }

            if (!isset($allowed[$mime])) {
                return Response::json(['status' => 'error', 'message' => 'Tipo de arquivo não permitido'], 400);
            }

            if ($file['size'] > 5 * 1024 * 1024) {
                return Response::json(['status' => 'error', 'message' => 'Arquivo muito grande. Máximo 5MB.'], 400);
            }

            $type = $_POST['type'] ?? 'avatar';
            $userUuid = $authUser->getUuid()->toString();

            $projectRoot = dirname(__DIR__, 3);
            $publicDir = $projectRoot . '/public';

            // Definir diretório baseado no tipo
            if ($type === 'avatar') {
                $targetDir = $publicDir . '/assets/images/userPerfil/' . $userUuid;
                $urlBase = '/assets/images/userPerfil/' . $userUuid;
            } else {
                $targetDir = $publicDir . '/assets/images/userCapa/' . $userUuid;
                $urlBase = '/assets/images/userCapa/' . $userUuid;
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $ext = $allowed[$mime];

            // Remover todas as imagens anteriores do diretório do usuário
            foreach (glob($targetDir . '/*') as $existingFile) {
                @unlink($existingFile);
            }

            $filename = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $destination = $targetDir . '/' . $filename;

            $isVector = str_starts_with($mime, 'image/svg');
            $isRaster = in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true);
            $maxWidth = $type === 'avatar' ? 512 : 1600;
            $maxHeight = $type === 'avatar' ? 512 : 900;

            if ($isRaster) {
                $saved = ImageProcessor::resizeAndSave($file['tmp_name'], $destination, $mime, $maxWidth, $maxHeight, 82);
                if (!$saved) {
                    return Response::json(['status' => 'error', 'message' => 'Falha ao processar imagem'], 500);
                }
            } else {
                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    return Response::json(['status' => 'error', 'message' => 'Falha ao salvar arquivo'], 500);
                }
            }

            // URL publica absoluta baseada nas variáveis do .env
            $apiBase = getenv('APP_URL')
                ?: ($_ENV['APP_URL'] ?? '')
                ?: ($_ENV['APP_URL_FRONTEND'] ?? '');

            if (!$apiBase && isset($_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $apiBase = $scheme . $_SERVER['HTTP_HOST'];
            }

            $apiBase = rtrim($apiBase, '/');
            $urlPath = $urlBase . '/' . $filename;
            $absoluteUrl = $apiBase . '/public' . $urlPath;

            return Response::json(['status' => 'success', 'url' => $absoluteUrl]);

        } catch (Throwable $e) {
            $debug = (($_ENV['APP_DEBUG'] ?? 'false') === 'true');
            return Response::json(['status' => 'error', 'message' => 'Erro ao processar upload', 'details' => $debug ? $e->getMessage() : null], 500);
        }
    }

    public function perfil($request): Response
    {
        $authUser = $request->attribute('auth_user');
        if (!$authUser) {
            return Response::json(['status' => 'error', 'message' => 'Não autenticado'], 401);
        }

        try {
            $uuid = $authUser->getUuid()->toString();
            $usuario = $this->service->buscarPorUuid($uuid);
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
                    'ativo' => $usuario->isAtivo(),
                    'criado_em' => $usuario->getCriadoEm()->format('c'),
                    'url_avatar' => $usuario->getUrlAvatar(),
                    'url_capa' => $usuario->getUrlCapa(),
                    'biografia' => $usuario->getBiografia(),
                    'status_verificacao' => $usuario->getStatusVerificacao(),
                    'verificado_email' => $usuario->getStatusVerificacao() === 'verificado' || $usuario->isEmailVerificado(),
                ]
            ]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao obter perfil'], 500);
        }
    }

    public function alterarEmail($request): Response
    {
        $authUser = $request->attribute('auth_user');
        if (!$authUser) {
            return Response::json(['status' => 'error', 'message' => 'Não autenticado'], 401);
        }

        $body = $request->body ?? [];
        $novoEmail = trim((string)($body['novo_email'] ?? $body['email'] ?? ''));
        if ($novoEmail === '' || !filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['status' => 'error', 'message' => 'E-mail inválido.'], 400);
        }

        $uuid = $authUser->getUuid()->toString();
        try {
            $usuario = $this->service->buscarPorUuid($uuid);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado'], 404);
            }
            if (strtolower($usuario->getEmail()) === strtolower($novoEmail)) {
                return Response::json(['status' => 'success', 'message' => 'E-mail já está atualizado.']);
            }
            if ($this->service->emailExiste($novoEmail)) {
                return Response::json(['status' => 'error', 'message' => 'E-mail já cadastrado.'], 409);
            }
            if (!$this->mailer()) {
                return Response::json(['status' => 'error', 'message' => 'Serviço de e-mail não disponível'], 503);
            }

            $this->service->atualizar($uuid, [
                'email' => $novoEmail,
                'status_verificacao' => 'pendente',
            ]);
            $this->service->marcarEmailComoVerificado($uuid, false);

            $token = bin2hex(random_bytes(32));
            $this->service->salvarTokenVerificacaoEmail($uuid, $token);
            $link = $this->montarLinkVerificacao($token);
            $this->mailer()->sendConfirmation($novoEmail, $usuario->getNomeCompleto(), $link, $_ENV['APP_LOGO_URL'] ?? null);

            return Response::json(['status' => 'success', 'message' => 'Confirme o novo e-mail para concluir a alteração.']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao alterar e-mail'], 500);
        }
    }

    public function enviarVerificacaoEmail($request, string $uuid): Response
    {
        try {
            $usuario = $this->service->buscarPorUuid($uuid);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado'], 404);
            }
            if ($usuario->isEmailVerificado() || strtolower($usuario->getStatusVerificacao()) === 'verificado') {
                return Response::json(['status' => 'success', 'message' => 'E-mail já verificado.'], 200);
            }
            if (!$this->mailer()) {
                return Response::json(['status' => 'error', 'message' => 'Serviço de e-mail não disponível'], 503);
            }

            $token = bin2hex(random_bytes(32));
            $this->service->salvarTokenVerificacaoEmail($uuid, $token);
            $link = $this->montarLinkVerificacao($token);
            $this->mailer()->sendConfirmation($usuario->getEmail(), $usuario->getNomeCompleto(), $link, $_ENV['APP_LOGO_URL'] ?? null);

            return Response::json(['status' => 'success', 'message' => 'E-mail de verificação enviado.']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao enviar e-mail de verificação'], 500);
        }
    }

    public function enviarVerificacaoEmailPorEmail($request): Response
    {
        try {
            $email = $request->getQueryParam('email');
            $email = is_string($email) ? trim($email) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::json(['status' => 'error', 'message' => 'E-mail inválido ou não informado.'], 400);
            }
            $usuario = $this->service->buscarPorEmail($email);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'E-mail não encontrado.'], 404);
            }
            return $this->enviarVerificacaoEmail($request, $usuario->getUuid()->toString());
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao enviar e-mail de verificação'], 500);
        }
    }

    public function verificarEmail($request, string $token): Response
    {
        try {
            $usuario = $this->service->buscarPorTokenVerificacaoEmail($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido'], 400);
            }
            $this->service->marcarEmailComoVerificado($usuario->getUuid()->toString());
            return Response::json([
                'status' => 'success',
                'message' => 'E-mail verificado com sucesso!',
                'uuid' => $usuario->getUuid()->toString()
            ]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao verificar e-mail'], 500);
        }
    }

    public function verificarEmailStatus($request): Response
    {
        try {
            $email = $request->getQueryParam('email');
            $email = is_string($email) ? trim($email) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::json(['status' => 'error', 'message' => 'E-mail inválido ou não informado.'], 400);
            }
            $usuario = $this->service->buscarPorEmail($email);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'E-mail não encontrado.'], 404);
            }
            return Response::json([
                'status' => 'success',
                'email' => $usuario->getEmail(),
                'verificado_email' => $usuario->isEmailVerificado(),
                'status_verificacao' => $usuario->getStatusVerificacao(),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao verificar status do e-mail'], 500);
        }
    }

    /**
     * GET /usuario/{uuid}
     */
    public function buscar($request): Response
    {
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::json(['error' => 'UUID não informado'], 400);
        }
        $usuario = $this->service->buscarPorUuid($uuid);
        if (!$usuario) {
            return Response::json([
                'status' => 'error',
                'message' => 'Usuário não encontrado'
            ], 404);
        }
        $apiBase = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
        $avatar = $usuario->getUrlAvatar();
        $capa = $usuario->getUrlCapa();
        $avatarUrl = $avatar && str_starts_with($avatar, '/assets/images/')
            ? $apiBase . '/public' . $avatar
            : $avatar;
        $capaUrl = $capa && str_starts_with($capa, '/assets/images/')
            ? $apiBase . '/public' . $capa
            : $capa;

        return Response::json([
            'usuario' => [
                'uuid' => $usuario->getUuid()->toString(),
                'nome_completo' => $usuario->getNomeCompleto(),
                'username' => $usuario->getUsername(),
                'email' => $usuario->getEmail(),
                'ativo' => $usuario->isAtivo(),
                'nivel_acesso' => $usuario->getNivelAcesso(),
                'criado_em' => $usuario->getCriadoEm()->format('c'),
                'url_avatar' => $avatarUrl,
                'url_capa' => $capaUrl,
                'biografia' => $usuario->getBiografia(),
                'status_verificacao' => $usuario->getStatusVerificacao(),
                'verificado_email' => $usuario->getStatusVerificacao() === 'verificado',
            ]
        ]);
    }

    /**
     * GET /api/perfil/{username} - Endpoint público para buscar perfil por username
     */
    public function buscarPorUsername($request): Response
    {
        try {
            $username = $request->param('username');
            
            if (!$username) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Username não informado'
                ], 400);
            }

            $usuario = $this->service->buscarPorUsername($username);

            if (!$usuario) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Usuário não encontrado'
                ], 404);
            }

            // Retorna apenas informações públicas do perfil
            return Response::json([
                'status' => 'success',
                'usuario' => [
                    'uuid' => $usuario->getUuid()->toString(),
                    'nome_completo' => $usuario->getNomeCompleto(),
                    'username' => $usuario->getUsername(),
                    'ativo' => $usuario->isAtivo(),
                    'criado_em' => $usuario->getCriadoEm()->format('c'),
                    'url_avatar' => $usuario->getUrlAvatar(),
                    'url_capa' => $usuario->getUrlCapa(),
                    'biografia' => $usuario->getBiografia(),
                    'status_verificacao' => $usuario->getStatusVerificacao(),
                ]
            ]);
        } catch (\Throwable $e) {
            $debug = getenv('APP_DEBUG') === 'true';
            return Response::json([
                'status' => 'error',
                'message' => 'Erro ao buscar perfil',
                'details' => $debug ? $e->getMessage() : 'Contate o suporte'
            ], 500);
        }
    }

    /**
     * GET /perfil/{username}
     */
    public function exibirPerfilHtml($request): Response
    {
        $username = $request->param('username');
        if (!$username) {
            return Response::html('<h1>Perfil não encontrado</h1>', 404);
        }

        $usuario = $this->service->buscarPorUsername($username);
        if (!$usuario) {
            return Response::html('<h1>Perfil não encontrado</h1>', 404);
        }

        $baseUrl = $_ENV['APP_URL'] ?? ($_ENV['APP_URL_FRONTEND'] ?? '');
        if (!$baseUrl && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        $baseUrl = rtrim((string) $baseUrl, '/');

        $normalizeUrl = function (?string $url) use ($baseUrl): ?string {
            if (!$url) {
                return null;
            }
            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:')) {
                return $url;
            }
            if ($baseUrl === '') {
                return $url;
            }
            if (str_starts_with($url, '/')) {
                return $baseUrl . $url;
            }
            return $baseUrl . '/' . $url;
        };

        $nome = $usuario->getNomeCompleto() ?: $usuario->getUsername();
        $titulo = $nome
            ? $nome . ' (@' . $usuario->getUsername() . ') | Orkeep Comunidade'
            : 'Perfil | Orkeep Comunidade';

        $descricao = $usuario->getBiografia();
        if (!$descricao) {
            $descricao = 'Perfil de ' . $usuario->getUsername() . ' na Orkeep Comunidade.';
        }

        $imagem = $normalizeUrl($usuario->getUrlAvatar() ?: $usuario->getUrlCapa());
        $imagemTipo = null;
        if ($imagem) {
            $ext = strtolower(pathinfo(parse_url($imagem, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $imagemTipo = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => null,
            };
        }

        $canonicalUrl = $normalizeUrl('/perfil/' . $usuario->getUsername());

        $dados = [
            'titulo' => $titulo,
            'descricao' => $descricao,
            'imagem' => $imagem,
            'imagem_tipo' => $imagemTipo,
            'url' => $canonicalUrl,
            'alt' => 'Foto de perfil',
        ];

        ob_start();
        extract($dados);
        include __DIR__ . '/../../Templates/perfil.php';
        $html = ob_get_clean();
        return Response::html($html);
    }

    /**
     * DELETE /usuario/{uuid}
     */
    public function deletar($request): Response
    {
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::json(['error' => 'UUID não informado'], 400);
        }
        try {
            $usuario = $this->service->buscarPorUuid($uuid);
            if (!$usuario) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Usuário não encontrado'
                ], 404);
            }
            $urlsImagensPublicacoes = [];
            try {
                if (class_exists(PublicacaoRepository::class)) {
                    $pdo = PdoFactory::fromEnv();
                    $pubRepo = new PublicacaoRepository($pdo);
                    $urlsImagensPublicacoes = $pubRepo->buscarImagensPorAutor($uuid);
                }
            } catch (\Throwable $e) {
                $urlsImagensPublicacoes = [];
            }
            $this->service->deletar($uuid);
            $this->removerArquivosUsuario($usuario->getUrlAvatar(), $usuario->getUrlCapa(), $uuid, $urlsImagensPublicacoes);
            return Response::json([
                'status' => 'success',
                'message' => 'Usuário excluído com sucesso'
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * DELETE /api/perfil
     * Exclui somente a conta do usuário autenticado
     */
    public function deletarMinhaConta($request): Response
    {
        $authUser = $request->attribute('auth_user');

        if (!$authUser) {
            return Response::json(['error' => 'Não autenticado'], 401);
        }
        try {
            $uuidToDelete = $authUser->getUuid()->toString();
            $usuario = $this->service->buscarPorUuid($uuidToDelete);
            if (!$usuario) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'Usuário não encontrado'
                ], 404);
            }
            $urlsImagensPublicacoes = [];
            try {
                if (class_exists(PublicacaoRepository::class)) {
                    $pdo = PdoFactory::fromEnv();
                    $pubRepo = new PublicacaoRepository($pdo);
                    $urlsImagensPublicacoes = $pubRepo->buscarImagensPorAutor($uuidToDelete);
                }
            } catch (\Throwable $e) {
                $urlsImagensPublicacoes = [];
            }
            $this->service->deletar($uuidToDelete);
            $this->removerArquivosUsuario($usuario->getUrlAvatar(), $usuario->getUrlCapa(), $uuidToDelete, $urlsImagensPublicacoes);
            return Response::json([
                'status' => 'success',
                'message' => 'Usuário excluído com sucesso'
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

     private function removerArquivosUsuario(?string $urlAvatar, ?string $urlCapa, string $uuid, array $urlsImagensPublicacoes): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $publicDir = $projectRoot . '/public';

        foreach ($urlsImagensPublicacoes as $urlImagem) {
            $this->removerArquivoPorUrl($urlImagem, $publicDir, '/assets/images/publicacoes/');
        }

        $this->removerArquivoPorUrl($urlAvatar, $publicDir, '/assets/images/userPerfil/');
        $this->removerArquivoPorUrl($urlCapa, $publicDir, '/assets/images/userCapa/');

        $this->removerDiretorio($publicDir . '/assets/images/userPerfil/' . $uuid);
        $this->removerDiretorio($publicDir . '/assets/images/userCapa/' . $uuid);
    }

    private function removerArquivoPorUrl(?string $url, string $publicDir, string $segmentoPermitido): void
    {
        if (!$url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return;
        }

        $relativePath = null;
        if (str_contains($path, '/public/assets/')) {
            $relativePath = str_replace('/public', '', $path);
        } elseif (str_starts_with($path, '/assets/')) {
            $relativePath = $path;
        }

        if (!$relativePath || !str_contains($relativePath, $segmentoPermitido)) {
            return;
        }

        $filePath = $publicDir . $relativePath;
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function removerDiretorio(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * PATCH /usuario/{uuid}/desativar
     */
    public function desativar($request): Response
    {
        $authUser = $request->attribute('auth_user');
        $isSystemAdmin = $authUser && method_exists($authUser, 'getNivelAcesso') && $authUser->getNivelAcesso() === 'admin_system';
        $uuid = $request->param('uuid');

        if (!$authUser || !$isSystemAdmin) {
            return Response::json(['error' => 'Acesso restrito a administradores.'], 403);
        }

        if (!$uuid) {
            return Response::json(['error' => 'UUID não informado'], 400);
        }

        try {
            $this->service->desativar($uuid);
            return Response::json([
                'status' => 'success',
                'message' => 'Usuário desativado com sucesso'
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * PATCH /usuario/{uuid}/ativar
     */
    public function ativar($request): Response
    {
        $authUser = $request->attribute('auth_user');
        $isSystemAdmin = $authUser && method_exists($authUser, 'getNivelAcesso') && $authUser->getNivelAcesso() === 'admin_system';
        $uuid = $request->param('uuid');

        if (!$authUser || !$isSystemAdmin) {
            return Response::json(['error' => 'Acesso restrito a administradores.'], 403);
        }

        if (!$uuid) {
            return Response::json(['error' => 'UUID não informado'], 400);
        }

        try {
            $this->service->ativar($uuid);
            return Response::json([
                'status' => 'success',
                'message' => 'Usuário ativado com sucesso'
            ]);
        } catch (DomainException $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    private function politicaVerificacaoAtiva(): bool
    {
        $caminho = dirname(__DIR__, 4) . '/storage/auth_policy.json';
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

    private function mailer(): ?EmailSenderInterface
    {
        return $this->emailService;
    }

    private function montarLinkVerificacao(string $token): string
    {
        $base = rtrim($_ENV['APP_URL_FRONTEND'] ?? $_ENV['APP_URL'] ?? '', '/');
        if ($base === '') {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = $scheme . '://' . $host;
        }
        return $base . '/api/auth/verify-email?token=' . urlencode($token);
    }
}
