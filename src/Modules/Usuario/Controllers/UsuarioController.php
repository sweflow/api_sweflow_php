<?php

namespace Src\Modules\Usuario\Controllers;

use DomainException;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\EmailThrottle;
use Src\Kernel\Utils\ImageProcessor;
use Src\Kernel\Utils\Sanitizer;
use Src\Modules\Usuario\Entities\Usuario;
use Src\Modules\Usuario\Exceptions\DomainException as ModuleDomainException;
use Src\Modules\Usuario\Services\UsuarioServiceInterface;

class UsuarioController
{
    private bool $debug;

    public function __construct(
        private readonly UsuarioServiceInterface              $service,
        private readonly \Src\Kernel\Support\EmailThrottle   $emailThrottle,
        private readonly ?\Src\Kernel\Contracts\EmailSenderInterface $emailSender = null,
    ) {
        $this->debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
            || ($_ENV['APP_DEBUG'] ?? '') === '1';
    }

    // ── Registro público ──────────────────────────────────────────────────

    public function criar(Request $request): Response
    {
        try {
            $body     = $request->body ?? [];
            $nome     = Sanitizer::string($body['nome_completo'] ?? $body['nome'] ?? '', 150);
            $username = Sanitizer::username($body['username'] ?? '');
            $email    = Sanitizer::email($body['email'] ?? '');
            $senha    = Sanitizer::password($body['senha'] ?? $body['password'] ?? '');
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
            $email = Sanitizer::email($body['email'] ?? '');

            if ($email === '') {
                return Response::json(['status' => 'error', 'message' => 'E-mail inválido ou não informado.'], 422);
            }

            $usuario = $this->service->buscarPorEmail($email);

            // Resposta genérica em todos os casos para não revelar se o e-mail existe
            $msgGenerica = 'Se o e-mail existir e não estiver verificado, um novo link será enviado em breve.';

            if (!$usuario) {
                // Registra throttle mesmo assim para evitar enumeração por timing
                $this->tentarRegistrarReenvioVerificacao($email);
                return Response::json(['status' => 'success', 'message' => $msgGenerica]);
            }

            if ($usuario->isEmailVerificado()) {
                return Response::json(['status' => 'success', 'message' => 'E-mail já verificado. Você pode fazer login normalmente.']);
            }

            // enviarEmailConfirmacaoRegistro já verifica throttle e e-mail verificado internamente
            $this->enviarEmailConfirmacaoRegistro($usuario);

            return Response::json(['status' => 'success', 'message' => $msgGenerica]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao reenviar verificação.'], 500);
        }
    }

    /**
     * Tenta registrar o throttle de verificação de forma atômica.
     * Retorna true se autorizado e registrado, false se ainda no cooldown.
     */
    private function tentarRegistrarReenvioVerificacao(string $email): bool
    {
        try {
            return $this->emailThrottle->tryRecord('verification', $email);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Gera token de verificação e envia e-mail de confirmação.
     * Não envia se o e-mail já estiver verificado ou throttle ativo.
     */
    private function enviarEmailConfirmacaoRegistro(\Src\Modules\Usuario\Entities\Usuario $usuario): void
    {
        // Só envia se o módulo de e-mail estiver instalado E habilitado
        if ($this->emailSender === null || !$this->emailModuleEnabled()) {
            return;
        }
        if ($usuario->isEmailVerificado()) {
            return;
        }

        // tryRecord é atômico — verifica cooldown e registra em uma única operação
        if (!$this->tentarRegistrarReenvioVerificacao($usuario->getEmail())) {
            return;
        }

        $this->dispararEmailVerificacao($usuario);
    }

    /**
     * Verifica se o módulo de e-mail está instalado e habilitado no marketplace.
     * Mesmo que MAILER_HOST esteja configurado, o envio só ocorre se o módulo estiver ativo.
     */
    private function emailModuleEnabled(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;

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

    /**
     * Envia e-mail de verificação sem verificar throttle.
     * Usado por ações administrativas explícitas.
     */
    private function enviarEmailVerificacaoForcado(\Src\Modules\Usuario\Entities\Usuario $usuario): void
    {
        if ($this->emailSender === null) {
            return;
        }
        $this->dispararEmailVerificacao($usuario);
    }

    /**
     * Gera token, persiste e envia o e-mail de verificação.
     * Lógica compartilhada entre envio normal (com throttle) e forçado (admin).
     */
    private function dispararEmailVerificacao(\Src\Modules\Usuario\Entities\Usuario $usuario): void
    {
        try {
            $token = bin2hex(random_bytes(32));
            $this->service->salvarTokenVerificacaoEmail($usuario->getUuid()->toString(), $token);

            // Rota correta: GET /api/auth/verify-email?token=...
            $link = \Src\Kernel\Support\UrlHelper::to('api/auth/verify-email?token=' . urlencode($token));

            $this->emailSender->sendConfirmation(
                $usuario->getEmail(),
                $usuario->getNomeCompleto(),
                $link,
                $_ENV['APP_LOGO_URL'] ?? null
            );
        } catch (\Throwable $e) {
            error_log('[UsuarioController] Falha ao enviar e-mail de verificação: ' . $e->getMessage());
        }
    }

    // ── Admin: listagem e gerenciamento ───────────────────────────────────

    public function listar(Request $request): Response
    {
        try {
            $pagina    = Sanitizer::positiveInt($request->query['pagina'] ?? $request->query['page'] ?? 1, 1, 10000);
            $porPagina = Sanitizer::positiveInt($request->query['por_pagina'] ?? $request->query['per_page'] ?? 20, 1, 100);
            $busca     = Sanitizer::search($request->query['q'] ?? '');
            $nivel     = Sanitizer::nivelAcesso($request->query['nivel'] ?? '');

            $resultado = $this->service->listarComFiltro($pagina, $porPagina, $busca, $nivel);

            return Response::json([
                'status'        => 'success',
                'pagina'        => $pagina,
                'por_pagina'    => $porPagina,
                'total'         => $resultado['total'],
                'total_paginas' => $resultado['total_paginas'],
                'usuarios'      => array_map([$this, 'serializar'], $resultado['usuarios']),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao listar usuários.', 'details' => $this->debug($e)], 500);
        }
    }

    public function buscar(Request $request, string $uuid): Response
    {
        try {
            $uuid = \Src\Kernel\Utils\Sanitizer::uuid($uuid);
            if ($uuid === '') {
                return Response::json(['status' => 'error', 'message' => 'Usuário não encontrado.'], 404);
            }
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
            $uuid = Sanitizer::uuid($uuid);
            if ($uuid === '') {
                return Response::json(['status' => 'error', 'message' => 'UUID inválido.'], 422);
            }
            $body = $request->body ?? [];
            if (empty($body)) {
                return Response::json(['status' => 'error', 'message' => 'Nenhum dado enviado.'], 422);
            }
            $dados = $this->sanitizarCamposUsuario($body);
            if (isset($body['nivel_acesso'])) {
                // Impede que o usuário logado altere seu próprio nível de acesso
                $authUser = $request->attribute('auth_user');
                if ($authUser && $authUser->getUuid()->toString() === $uuid) {
                    return Response::json(['status' => 'error', 'message' => 'Você não pode alterar seu próprio nível de acesso.'], 403);
                }
                $nivelError = $this->sanitizarNivelAcesso($body['nivel_acesso'], $dados);
                if ($nivelError !== null) {
                    return $nivelError;
                }
            }
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

    /** Sanitiza os campos comuns de usuário a partir do body da requisição. */
    private function sanitizarCamposUsuario(array $body): array
    {
        $map = [
            'nome_completo' => static fn($v) => Sanitizer::string($v, 150),
            'username'      => static fn($v) => Sanitizer::username($v),
            'email'         => static fn($v) => Sanitizer::email($v),
            'senha'         => static fn($v) => Sanitizer::password($v),
            'url_avatar'    => static fn($v) => Sanitizer::url($v),
            'url_capa'      => static fn($v) => Sanitizer::url($v),
            'biografia'     => static fn($v) => Sanitizer::text($v, 500),
        ];
        $dados = [];
        foreach ($map as $campo => $sanitize) {
            if (isset($body[$campo])) {
                $dados[$campo] = $sanitize($body[$campo]);
            }
        }
        return $dados;
    }

    /** Valida e adiciona nivel_acesso em $dados. Retorna Response de erro ou null se ok. */
    private function sanitizarNivelAcesso(mixed $valor, array &$dados): ?Response
    {
        $nivel = Sanitizer::nivelAcesso($valor);
        if ($nivel === '') {
            return Response::json(['status' => 'error', 'message' => 'Nível de acesso inválido.'], 422);
        }
        $dados['nivel_acesso'] = $nivel;
        return null;
    }

    public function deletar(Request $request, string $uuid): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if ($authUser && $authUser->getUuid()->toString() === $uuid) {
                return Response::json(['status' => 'error', 'message' => 'Você não pode excluir sua própria conta por aqui.'], 403);
            }
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

            $body  = $request->body ?? [];
            // Filtra apenas campos permitidos para perfil (sem email, senha e nivel_acesso)
            $perfilKeys = ['nome_completo', 'username', 'url_avatar', 'url_capa', 'biografia'];
            $bodyFiltrado = array_intersect_key($body, array_flip($perfilKeys));
            $dados = $this->sanitizarCamposUsuario($bodyFiltrado);

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
            $email = Sanitizer::email($body['email'] ?? '');
            $senha = Sanitizer::password($body['senha'] ?? $body['password'] ?? '');

            if ($email === '') {
                return Response::json(['status' => 'error', 'message' => 'E-mail inválido ou não informado.'], 422);
            }
            if ($senha === '' || !$authUser->verificarSenha($senha)) {
                return Response::json(['status' => 'error', 'message' => 'Senha incorreta.'], 403);
            }

            $uuid = $authUser->getUuid()->toString();
            $this->service->atualizar($uuid, ['email' => $email]);

            // Reseta verificação no banco e envia e-mail de confirmação para o novo endereço.
            // A ordem importa: resetar primeiro, depois buscar o objeto atualizado,
            // para que isEmailVerificado() retorne false e o envio não seja bloqueado.
            $this->service->resetarVerificacaoEmail($uuid);
            $usuarioAtualizado = $this->service->buscarPorUuid($uuid);
            if ($usuarioAtualizado) {
                $this->enviarEmailConfirmacaoRegistro($usuarioAtualizado);
            }

            return Response::json(['status' => 'success', 'message' => 'E-mail atualizado. Verifique sua caixa de entrada para confirmar o novo endereço.']);
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
            $senhaAtual  = Sanitizer::password($body['senha_atual'] ?? $body['current_password'] ?? '');
            $novaSenha   = Sanitizer::password($body['nova_senha'] ?? $body['new_password'] ?? '');

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

            $tipo  = $this->resolverTipoImagem($request->body['tipo'] ?? 'avatar');
            $file  = $_FILES['imagem'] ?? $_FILES['file'] ?? null;

            $validationError = $this->validarArquivoImagem($file);
            if ($validationError !== null) {
                return $validationError;
            }

            $mime = mime_content_type($file['tmp_name']) ?: '';
            $mimeError = $this->validarMimeImagem($mime);
            if ($mimeError !== null) {
                return $mimeError;
            }

            $uuid      = $authUser->getUuid()->toString();
            $filename  = $this->gerarNomeArquivo($uuid, $tipo, $mime);
            $uploadDir = dirname(__DIR__, 5) . '/public/uploads/perfil/';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return Response::json(['status' => 'error', 'message' => 'Erro ao criar diretório de upload.'], 500);
            }

            [$maxW, $maxH] = $tipo === 'capa' ? [1200, 400] : [400, 400];
            if (!ImageProcessor::resizeAndSave($file['tmp_name'], $uploadDir . $filename, $mime, $maxW, $maxH)) {
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

    private function resolverTipoImagem(mixed $tipo): string
    {
        $tipo = trim((string) $tipo);
        return in_array($tipo, ['avatar', 'capa'], true) ? $tipo : 'avatar';
    }

    private function validarArquivoImagem(?array $file): ?Response
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['status' => 'error', 'message' => 'Nenhuma imagem enviada ou erro no upload.'], 422);
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return Response::json(['status' => 'error', 'message' => 'Imagem muito grande. Máximo 5MB.'], 422);
        }

        // Validação de conteúdo via finfo (mais confiável que mime_content_type)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? (finfo_file($finfo, $file['tmp_name']) ?: '') : '';
        if ($finfo) finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if ($realMime !== '' && !in_array($realMime, $allowedMimes, true)) {
            return Response::json(['status' => 'error', 'message' => 'Arquivo inválido: não é uma imagem permitida.'], 422);
        }

        // Dupla validação: getimagesize verifica estrutura interna do arquivo
        set_error_handler(static function (): bool { return true; });
        $imageInfo = getimagesize($file['tmp_name']);
        restore_error_handler();
        if ($imageInfo === false) {
            return Response::json(['status' => 'error', 'message' => 'Arquivo inválido: não é uma imagem.'], 422);
        }
        return null;
    }

    private function validarMimeImagem(string $mime): ?Response
    {
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            return Response::json(['status' => 'error', 'message' => 'Formato não suportado. Use JPEG, PNG ou WebP.'], 422);
        }
        return null;
    }

    private function gerarNomeArquivo(string $uuid, string $tipo, string $mime): string
    {
        $extMap = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $ext    = $extMap[$mime] ?? 'jpg';
        // uniqid com more_entropy evita colisões em uploads simultâneos no mesmo segundo
        return $uuid . '_' . $tipo . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    public function deletarMinhaConta(Request $request): Response
    {
        try {
            $authUser = $request->attribute('auth_user');
            if (!$authUser) {
                return Response::json(['status' => 'error', 'message' => 'Não autenticado.'], 401);
            }

            $body = $request->body ?? [];
            $senha = Sanitizer::password($body['senha'] ?? $body['password'] ?? '');
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
            $html = "<!doctype html><html lang='pt-BR'><head><meta charset='utf-8'>"
                  . "<meta name='viewport' content='width=device-width, initial-scale=1'>"
                  . "<title>{$nome}</title></head><body>"
                  . ($avatar ? "<img src='{$avatar}' alt='Avatar de {$nome}'>" : '')
                  . "<h1>{$nome}</h1><p>@{$usernameHtml}</p>"
                  . ($bio ? "<p>{$bio}</p>" : '')
                  . "</body></html>";
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
            if ($usuario->isEmailVerificado()) {
                return Response::json(['status' => 'success', 'message' => 'E-mail já verificado.']);
            }
            // Força envio ignorando throttle (ação administrativa explícita)
            $this->enviarEmailVerificacaoForcado($usuario);
            return Response::json(['status' => 'success', 'message' => 'E-mail de verificação enviado.']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Erro ao enviar e-mail.', 'details' => $this->debug($e)], 500);
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
                return Response::json(['status' => 'success', 'message' => 'Se o e-mail existir, o link será enviado.']);
            }
            if ($usuario->isEmailVerificado()) {
                return Response::json(['status' => 'success', 'message' => 'E-mail já verificado.']);
            }
            // Força envio ignorando throttle (ação administrativa explícita)
            $this->enviarEmailVerificacaoForcado($usuario);
            return Response::json(['status' => 'success', 'message' => 'E-mail de verificação enviado.']);
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
        return $this->debug ? $e->getMessage() : null;
    }
}
