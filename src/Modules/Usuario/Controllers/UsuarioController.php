<?php

namespace Src\Modules\Usuario\Controllers;

use DomainException;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Utils\ImageProcessor;
use Src\Modules\Usuario\Entities\Usuario;
use Src\Modules\Usuario\Exceptions\DomainException as ModuleDomainException;
use Src\Modules\Usuario\Services\UsuarioServiceInterface;

class UsuarioController
{
    public function __construct(
        private UsuarioServiceInterface $service,
        private ?\Src\Kernel\Contracts\EmailSenderInterface $emailSender = null
    ) {}

    // ── Registro público ──────────────────────────────────────────────────

    public function criar(Request $request): Response
    {
        try {
            $body = $request->body ?? [];
            $nome     = trim((string) ($body['nome_completo'] ?? $body['nome'] ?? ''));
            $username = trim((string) ($body['username'] ?? ''));
            $email    = trim((string) ($body['email'] ?? ''));
            $senha    = (string) ($body['senha'] ?? $body['password'] ?? '');
            // nivel_acesso nunca vem do body em registro público — sempre 'usuario'
            $nivel    = 'usuario';

            if ($nome === '' || $username === '' || $email === '' || $senha === '') {
                return Response::json(['status' => 'error', 'message' => 'Campos obrigatórios: nome_completo, username, email, senha.'], 422);
            }

            $usuario = Usuario::registrar($nome, $username, $email, $senha, null, null, null, $nivel);
            $this->service->criar($usuario);

            // Gera token e envia e-mail de confirmação se o módulo estiver disponível
            $this->enviarEmailConfirmacaoRegistro($usuario);

            return Response::json([
                'status'  => 'success',
                'message' => 'Usuário criado com sucesso. Verifique seu e-mail para confirmar o cadastro.',
                'usuario' => $this->serializar($usuario),
            ], 201);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao criar usuário.', 'details' => $this->debug($e)], 500);
        }
    }

    /**
     * Endpoint público: reenviar e-mail de verificação.
     * POST /api/auth/reenviar-verificacao  { "email": "..." }
     */
    public function reenviarVerificacaoEmail(Request $request): Response
    {
        try {
            $body  = $request->body ?? [];
            $email = trim((string) ($body['email'] ?? ''));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::json(['status' => 'error', 'message' => 'E-mail inválido ou não informado.'], 422);
            }

            // Throttle: máximo 1 reenvio a cada 120 segundos por e-mail
            if (!$this->podeReenviarVerificacao($email)) {
                return Response::json([
                    'status'  => 'success',
                    'message' => 'Um e-mail de confirmação já foi enviado recentemente. Aguarde alguns minutos antes de solicitar novamente.',
                ]);
            }

            $usuario = $this->service->buscarPorEmail($email);

            // Resposta genérica para não revelar se o e-mail existe
            if (!$usuario || $usuario->isEmailVerificado()) {
                // Registra o throttle mesmo assim para evitar enumeração de e-mails
                $this->registrarReenvioVerificacao($email);
                $msg = $usuario && $usuario->isEmailVerificado()
                    ? 'E-mail já verificado. Você pode fazer login normalmente.'
                    : 'Se o e-mail existir e não estiver verificado, um novo link será enviado.';
                return Response::json(['status' => 'success', 'message' => $msg]);
            }

            $this->registrarReenvioVerificacao($email);
            $this->enviarEmailConfirmacaoRegistro($usuario);

            return Response::json(['status' => 'success', 'message' => 'E-mail de confirmação enviado. Verifique sua caixa de entrada.']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao reenviar verificação.'], 500);
        }
    }

    private function podeReenviarVerificacao(string $email): bool
    {
        try {
            $pdo  = \Src\Kernel\Database\PdoFactory::fromEnv();
            $stmt = $pdo->prepare(
                "SELECT sent_at FROM email_throttle WHERE type = 'verification' AND email = :email"
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

    private function registrarReenvioVerificacao(string $email): void
    {
        try {
            $pdo    = \Src\Kernel\Database\PdoFactory::fromEnv();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                $pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at) VALUES ('verification', :email, NOW())
                     ON CONFLICT (type, email) DO UPDATE SET sent_at = NOW()"
                )->execute([':email' => strtolower(trim($email))]);
                $pdo->exec("DELETE FROM email_throttle WHERE sent_at < NOW() - INTERVAL '3600 seconds'");
            } else {
                $pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at) VALUES ('verification', :email, NOW())
                     ON DUPLICATE KEY UPDATE sent_at = NOW()"
                )->execute([':email' => strtolower(trim($email))]);
                $pdo->exec("DELETE FROM email_throttle WHERE sent_at < DATE_SUB(NOW(), INTERVAL 3600 SECOND)");
            }
        } catch (\Throwable $e) {
            error_log('[UsuarioController] throttle record failed: ' . $e->getMessage());
        }
    }

    /**
     * Gera token de verificação e envia e-mail de confirmação.
     */
    private function enviarEmailConfirmacaoRegistro(\Src\Modules\Usuario\Entities\Usuario $usuario): void
    {
        if ($this->emailSender === null) {
            return;
        }

        try {
            $token = bin2hex(random_bytes(32));
            $this->service->salvarTokenVerificacaoEmail($usuario->getUuid()->toString(), $token);

            $base = rtrim($_ENV['APP_URL_FRONTEND'] ?? $_ENV['APP_URL'] ?? '', '/');
            if ($base === '') {
                $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base   = $scheme . '://' . $host;
            }
            $link = $base . '/api/auth/verify-email?token=' . urlencode($token);

            $this->emailSender->sendConfirmation(
                $usuario->getEmail(),
                $usuario->getNomeCompleto(),
                $link,
                $_ENV['APP_LOGO_URL'] ?? null
            );
        } catch (\Throwable $e) {
            error_log('[UsuarioController] Falha ao enviar e-mail de confirmação: ' . $e->getMessage());
        }
    }

    // ── Admin: listagem e gerenciamento ───────────────────────────────────

    public function listar(Request $request): Response
    {
        try {
            $pagina    = max(1, (int) ($request->query['pagina'] ?? $request->query['page'] ?? 1));
            $porPagina = min(100, max(1, (int) ($request->query['por_pagina'] ?? $request->query['per_page'] ?? 20)));
            $usuarios  = $this->service->listar($pagina, $porPagina);

            return Response::json([
                'status'   => 'success',
                'pagina'   => $pagina,
                'usuarios' => array_map([$this, 'serializar'], $usuarios),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao listar usuários.', 'details' => $this->debug($e)], 500);
        }
    }

    public function buscar(Request $request, string $uuid): Response
    {
        try {
            $usuario = $this->service->buscarPorUuid($uuid);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado.'], 404);
            }
            return Response::json(['status' => 'success', 'usuario' => $this->serializar($usuario)]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao buscar usuário.', 'details' => $this->debug($e)], 500);
        }
    }

    public function atualizar(Request $request, string $uuid): Response
    {
        try {
            $body = $request->body ?? [];
            if (empty($body)) {
                return Response::json(['status' => 'error', 'message' => 'Nenhum dado enviado.'], 422);
            }
            // Whitelist de campos que admin pode alterar — nivel_acesso é explícito e intencional aqui
            $permitidos = ['nome_completo', 'username', 'email', 'senha', 'url_avatar', 'url_capa', 'biografia', 'nivel_acesso'];
            $dados = array_intersect_key($body, array_flip($permitidos));
            if (empty($dados)) {
                return Response::json(['status' => 'error', 'message' => 'Nenhum campo válido enviado.'], 422);
            }
            $this->service->atualizar($uuid, $dados);
            $usuario = $this->service->buscarPorUuid($uuid);
            return Response::json([
                'status'  => 'success',
                'message' => 'Usuário atualizado.',
                'usuario' => $usuario ? $this->serializar($usuario) : null,
            ]);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao atualizar usuário.', 'details' => $this->debug($e)], 500);
        }
    }

    public function deletar(Request $request, string $uuid): Response
    {
        try {
            $this->service->deletar($uuid);
            return Response::json(['status' => 'success', 'message' => 'Usuário removido.']);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao remover usuário.', 'details' => $this->debug($e)], 500);
        }
    }

    public function desativar(Request $request, string $uuid): Response
    {
        try {
            $this->service->desativar($uuid);
            return Response::json(['status' => 'success', 'message' => 'Usuário desativado.']);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao desativar usuário.', 'details' => $this->debug($e)], 500);
        }
    }

    public function ativar(Request $request, string $uuid): Response
    {
        try {
            $this->service->ativar($uuid);
            return Response::json(['status' => 'success', 'message' => 'Usuário ativado.']);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao ativar usuário.', 'details' => $this->debug($e)], 500);
        }
    }

    // ── Perfil do usuário autenticado ─────────────────────────────────────

    public function perfil(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }
            return Response::json(['status' => 'success', 'usuario' => $this->serializar($authUser)]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao buscar perfil.', 'details' => $this->debug($e)], 500);
        }
    }

    public function atualizarPerfil(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }

            $body = $request->body ?? [];
            // Campos permitidos para auto-edição (sem nivel_acesso)
            $permitidos = ['nome_completo', 'username', 'url_avatar', 'url_capa', 'biografia'];
            $dados = array_intersect_key($body, array_flip($permitidos));

            if (empty($dados)) {
                return Response::json(['status' => 'error', 'message' => 'Nenhum campo válido enviado.'], 422);
            }

            $uuid = $authUser->getUuid()->toString();
            $this->service->atualizar($uuid, $dados);
            $usuario = $this->service->buscarPorUuid($uuid);

            return Response::json([
                'status'  => 'success',
                'message' => 'Perfil atualizado.',
                'usuario' => $usuario ? $this->serializar($usuario) : null,
            ]);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao atualizar perfil.', 'details' => $this->debug($e)], 500);
        }
    }

    public function alterarEmail(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }

            $body  = $request->body ?? [];
            $email = trim((string) ($body['email'] ?? ''));
            $senha = (string) ($body['senha'] ?? $body['password'] ?? '');

            if ($email === '') {
                return Response::json(['status' => 'error', 'message' => 'E-mail é obrigatório.'], 422);
            }
            if ($senha === '' || !$authUser->verificarSenha($senha)) {
                return Response::json(['status' => 'error', 'message' => 'Senha incorreta.'], 403);
            }

            $uuid = $authUser->getUuid()->toString();
            $this->service->atualizar($uuid, ['email' => $email]);

            return Response::json(['status' => 'success', 'message' => 'E-mail atualizado.']);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao alterar e-mail.', 'details' => $this->debug($e)], 500);
        }
    }

    public function alterarSenha(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }

            $body        = $request->body ?? [];
            $senhaAtual  = (string) ($body['senha_atual'] ?? $body['current_password'] ?? '');
            $novaSenha   = (string) ($body['nova_senha'] ?? $body['new_password'] ?? '');

            if ($senhaAtual === '' || $novaSenha === '') {
                return Response::json(['status' => 'error', 'message' => 'senha_atual e nova_senha são obrigatórios.'], 422);
            }
            if (!$authUser->verificarSenha($senhaAtual)) {
                return Response::json(['status' => 'error', 'message' => 'Senha atual incorreta.'], 403);
            }

            $this->service->alterarSenha($authUser->getUuid()->toString(), $novaSenha);
            return Response::json(['status' => 'success', 'message' => 'Senha alterada com sucesso.']);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao alterar senha.', 'details' => $this->debug($e)], 500);
        }
    }

    public function uploadProfileImage(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }

            $tipo = trim((string) ($request->body['tipo'] ?? 'avatar')); // avatar | capa
            // Whitelist de tipo para evitar path traversal no nome do arquivo
            if (!in_array($tipo, ['avatar', 'capa'], true)) {
                $tipo = 'avatar';
            }
            $file = $_FILES['imagem'] ?? $_FILES['file'] ?? null;

            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return Response::json(['status' => 'error', 'message' => 'Nenhuma imagem enviada ou erro no upload.'], 422);
            }

            $mime = mime_content_type($file['tmp_name']) ?: '';
            $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (!in_array($mime, $allowed, true)) {
                return Response::json(['status' => 'error', 'message' => 'Formato não suportado. Use JPEG, PNG ou WebP.'], 422);
            }

            // Valida tamanho máximo (5MB)
            if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
                return Response::json(['status' => 'error', 'message' => 'Imagem muito grande. Máximo 5MB.'], 422);
            }

            // Validação real de conteúdo — mime_content_type é falsificável
            if (@getimagesize($file['tmp_name']) === false) {
                return Response::json(['status' => 'error', 'message' => 'Arquivo inválido: não é uma imagem.'], 422);
            }

            // Whitelist de extensão — nunca usa extensão do nome original sem validar
            $extMap = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ext = $extMap[$mime] ?? 'jpg';
            $uuid     = $authUser->getUuid()->toString();
            $filename = $uuid . '_' . $tipo . '_' . time() . '.' . $ext;
            $uploadDir = dirname(__DIR__, 5) . '/public/uploads/perfil/';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return Response::json(['status' => 'error', 'message' => 'Erro ao criar diretório de upload.'], 500);
            }

            $destPath = $uploadDir . $filename;
            [$maxW, $maxH] = $tipo === 'capa' ? [1200, 400] : [400, 400];

            if (!ImageProcessor::resizeAndSave($file['tmp_name'], $destPath, $mime, $maxW, $maxH)) {
                return Response::json(['status' => 'error', 'message' => 'Falha ao processar imagem.'], 500);
            }

            $url   = '/uploads/perfil/' . $filename;
            $campo = $tipo === 'capa' ? 'url_capa' : 'url_avatar';
            $this->service->atualizar($uuid, [$campo => $url]);

            return Response::json(['status' => 'success', 'url' => $url]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro no upload.', 'details' => $this->debug($e)], 500);
        }
    }

    public function deletarMinhaConta(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }

            $body = $request->body ?? [];
            $senha = (string) ($body['senha'] ?? $body['password'] ?? '');
            if ($senha === '' || !$authUser->verificarSenha($senha)) {
                return Response::json(['status' => 'error', 'message' => 'Senha incorreta.'], 403);
            }

            $this->service->deletar($authUser->getUuid()->toString());
            return Response::json(['status' => 'success', 'message' => 'Conta removida.']);
        } catch (DomainException | ModuleDomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 422;
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao remover conta.', 'details' => $this->debug($e)], 500);
        }
    }

    // ── Perfil público ────────────────────────────────────────────────────

    public function buscarPorUsername(Request $request, string $username): Response
    {
        try {
            $usuario = $this->service->buscarPorUsername($username);
            if (!$usuario || !$usuario->isAtivo()) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado.'], 404);
            }
            return Response::json(['status' => 'success', 'usuario' => $this->serializarPublico($usuario)]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao buscar perfil.', 'details' => $this->debug($e)], 500);
        }
    }

    public function exibirPerfilHtml(Request $request, string $username): Response
    {
        try {
            $usuario = $this->service->buscarPorUsername($username);
            if (!$usuario || !$usuario->isAtivo()) {
                return Response::html('<h1>Usuário não encontrado</h1>', 404);
            }
            $nome       = htmlspecialchars($usuario->getNomeCompleto(), ENT_QUOTES, 'UTF-8');
            $usernameHtml = htmlspecialchars($usuario->getUsername(), ENT_QUOTES, 'UTF-8');
            $avatar     = htmlspecialchars($usuario->getUrlAvatar() ?? '', ENT_QUOTES, 'UTF-8');
            $bio        = htmlspecialchars($usuario->getBiografia() ?? '', ENT_QUOTES, 'UTF-8');
            $html = "<!doctype html><meta charset='utf-8'><title>{$nome}</title>"
                  . ($avatar ? "<img src='{$avatar}' alt='Avatar'>" : '')
                  . "<h1>{$nome}</h1><p>@{$usernameHtml}</p>"
                  . ($bio ? "<p>{$bio}</p>" : '');
            return Response::html($html);
        } catch (\Throwable $e) {
            return Response::html('<h1>Erro interno</h1>', 500);
        }
    }

    // ── Verificação de e-mail ─────────────────────────────────────────────

    public function enviarVerificacaoEmail(Request $request, string $uuid): Response
    {
        try {
            $usuario = $this->service->buscarPorUuid($uuid);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado.'], 404);
            }
            $token = bin2hex(random_bytes(32));
            $this->service->salvarTokenVerificacaoEmail($uuid, $token);
            return Response::json(['status' => 'success', 'message' => 'Token de verificação gerado.', 'token' => $token]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao gerar token.', 'details' => $this->debug($e)], 500);
        }
    }

    public function enviarVerificacaoEmailPorEmail(Request $request): Response
    {
        try {
            $body  = $request->body ?? [];
            $email = trim((string) ($body['email'] ?? ''));
            if ($email === '') {
                return Response::json(['status' => 'error', 'message' => 'E-mail é obrigatório.'], 422);
            }
            $usuario = $this->service->buscarPorEmail($email);
            if (!$usuario) {
                return Response::json(['status' => 'success', 'message' => 'Se o e-mail existir, um token será gerado.']);
            }
            $token = bin2hex(random_bytes(32));
            $this->service->salvarTokenVerificacaoEmail($usuario->getUuid()->toString(), $token);
            return Response::json(['status' => 'success', 'message' => 'Token de verificação gerado.', 'token' => $token]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao gerar token.', 'details' => $this->debug($e)], 500);
        }
    }

    public function verificarEmailStatus(Request $request): Response
    {
        try {
            $uuid = trim((string) ($request->query['uuid'] ?? ''));
            if ($uuid === '') {
                return Response::json(['status' => 'error', 'message' => 'UUID é obrigatório.'], 422);
            }
            $usuario = $this->service->buscarPorUuid($uuid);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado.'], 404);
            }
            return Response::json(['status' => 'success', 'verificado' => $usuario->isEmailVerificado()]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao verificar status.', 'details' => $this->debug($e)], 500);
        }
    }

    public function verificarEmail(Request $request, string $token): Response
    {
        try {
            $token = trim($token);
            if ($token === '') {
                return Response::json(['status' => 'error', 'message' => 'Token inválido.'], 400);
            }
            $usuario = $this->service->buscarPorTokenVerificacaoEmail($token);
            if (!$usuario) {
                return Response::json(['status' => 'error', 'message' => 'Token inválido ou expirado.'], 400);
            }
            if ($usuario->isEmailVerificado()) {
                return Response::json(['status' => 'success', 'message' => 'E-mail já verificado.']);
            }
            $this->service->marcarEmailComoVerificado($usuario->getUuid()->toString());
            return Response::json(['status' => 'success', 'message' => 'E-mail verificado com sucesso.']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao verificar e-mail.', 'details' => $this->debug($e)], 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function serializar(Usuario $u): array
    {
        return [
            'uuid'               => $u->getUuid()->toString(),
            'nome_completo'      => $u->getNomeCompleto(),
            'username'           => $u->getUsername(),
            'email'              => $u->getEmail(),
            'nivel_acesso'       => $u->getNivelAcesso(),
            'ativo'              => $u->isAtivo(),
            'verificado_email'   => $u->isEmailVerificado(),
            'url_avatar'         => $u->getUrlAvatar(),
            'url_capa'           => $u->getUrlCapa(),
            'biografia'          => $u->getBiografia(),
            'criado_em'          => $u->getCriadoEm()->format('Y-m-d\TH:i:sP'),
            'atualizado_em'      => $u->getAtualizadoEm()?->format('Y-m-d\TH:i:sP'),
        ];
    }

    private function serializarPublico(Usuario $u): array
    {
        return [
            'username'    => $u->getUsername(),
            'nome_completo' => $u->getNomeCompleto(),
            'url_avatar'  => $u->getUrlAvatar(),
            'url_capa'    => $u->getUrlCapa(),
            'biografia'   => $u->getBiografia(),
        ];
    }

    private function debug(\Throwable $e): ?string
    {
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? $e->getMessage() : null;
    }
}
