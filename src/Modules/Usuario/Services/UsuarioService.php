<?php

namespace src\Modules\Usuario\Services;

use src\Modules\Usuario\Entities\Usuario;
use src\Modules\Usuario\Repositories\UsuarioRepositoryInterface;
use DomainException;

class UsuarioService implements UsuarioServiceInterface
{
    public function emailExiste(string $email): bool
    {
        return $this->repository->emailExiste($email);
    }

    public function usernameExiste(string $username): bool
    {
        return $this->repository->usernameExiste($username);
    }
    public function __construct(
        private UsuarioRepositoryInterface $repository
    ) {}

    public function criar(Usuario $usuario): void
    {
        if ($this->repository->emailExiste($usuario->getEmail())) {
            throw new DomainException('E-mail já cadastrado.');
        }
        if ($this->repository->usernameExiste($usuario->getUsername())) {
            throw new DomainException('Username já cadastrado.');
        }
        $this->repository->salvar($usuario);
    }

    public function atualizar(string $uuid, array $data): void
    {
        try {
            $usuario = $this->repository->buscarPorUuid($uuid);
            if (!$usuario) {
                throw new DomainException('Usuário não encontrado.');
            }

            $camposPermitidos = [
                'nome_completo', 'username', 'email', 'senha', 'url_avatar', 'url_capa', 'biografia', 'nivel_acesso'
            ];
            $camposInvalidos = array_diff(array_keys($data), $camposPermitidos);
            if (!empty($camposInvalidos)) {
                throw new DomainException('Campos inválidos no update: ' . implode(', ', $camposInvalidos));
            }

            // Atualiza campos se enviados
            if (isset($data['nome_completo'])) {
                $usuario->setNomeCompleto($data['nome_completo']);
            }
            if (isset($data['username'])) {
                $usuario->setUsername($data['username']);
            }
            if (isset($data['email'])) {
                $usuario->setEmail($data['email']);
            }
            if (isset($data['senha'])) {
                $usuario->alterarSenha($data['senha']);
            }
            if (isset($data['url_avatar'])) {
                $usuario->setUrlAvatar($data['url_avatar']);
            }
            if (isset($data['url_capa'])) {
                $usuario->setUrlCapa($data['url_capa']);
            }
            if (isset($data['biografia'])) {
                $usuario->setBiografia($data['biografia']);
            }
            if (isset($data['nivel_acesso'])) {
                $usuario->promoverPara($data['nivel_acesso']);
            }
            $usuario->setAtualizadoEm(new \DateTimeImmutable());

            // Valida unicidade
            if ($this->repository->emailExiste($usuario->getEmail(), $uuid)) {
                throw new DomainException('E-mail já cadastrado.');
            }
            if ($this->repository->usernameExiste($usuario->getUsername(), $uuid)) {
                throw new DomainException('Username já cadastrado.');
            }
            $this->repository->salvar($usuario);
        } catch (\RuntimeException $e) {
            // Erro de persistência (ex: nenhuma linha afetada)
            throw new DomainException($e->getMessage(), 500, $e);
        } catch (\Throwable $e) {
            throw new DomainException($e->getMessage(), 500, $e);
        }
    }

    // Salva o token de verificação de e-mail
    public function salvarTokenVerificacaoEmail(string $uuid, string $token): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        // Permitir atualizar o token mesmo se já estiver verificado ou o campo estiver null
        $usuario->gerarTokenVerificacaoEmail($token);
        $this->repository->salvarTokenVerificacaoEmail($uuid, $token);
    }

    // Busca usuário pelo token de verificação de e-mail
    public function buscarPorTokenVerificacaoEmail(string $token): ?Usuario
    {
        return $this->repository->buscarPorTokenVerificacaoEmail($token);
    }

    public function buscarPorTokenRecuperacaoSenha(string $token): ?Usuario
    {
        return $this->repository->buscarPorTokenRecuperacaoSenha($token);
    }

    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        $usuario->gerarTokenRecuperacaoSenha($token);
        $this->repository->salvarTokenRecuperacaoSenha($uuid, $token);
    }

    public function limparTokenRecuperacaoSenha(string $uuid): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        $this->repository->limparTokenRecuperacaoSenha($uuid);
    }

    // Marca o e-mail como verificado
    public function marcarEmailComoVerificado(string $uuid): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        //$usuario->setStatusVerificacao('Verificado');
        $this->repository->marcarEmailComoVerificado($uuid);
    }

    /**
     * Verifica se a senha fornecida está correta para o usuário.
     */
    public function verificarSenha(string $uuid, string $senha): bool
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            return false;
        }
        // Supondo que Usuario tem método verificarSenha
        return $usuario->verificarSenha($senha);
    }

    /**
     * Altera a senha do usuário.
     */
    public function alterarSenha(string $uuid, string $novaSenha, bool $logoutAll = false): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        $usuario->alterarSenha($novaSenha);
        $this->repository->salvar($usuario);
        // Se logoutAll for true, implementar lógica de invalidar tokens/sessões aqui se necessário
    }

    public function buscarPorUuid(string $uuid): ?Usuario
    {
        return $this->repository->buscarPorUuid($uuid);
    }

    public function buscarPorUsername(string $username): ?Usuario
    {
        return $this->repository->buscarPorUsername($username);
    }

    public function buscarPorEmail(string $email): ?Usuario
    {
        return $this->repository->buscarPorEmail($email);
    }

    public function listar(int $pagina = 1, int $porPagina = 20): array
    {
        // Exemplo: busca paginada por nome vazio (todos)
        return $this->repository->buscarPorNomePaginado('', $pagina, $porPagina);
    }

    public function desativar(string $uuid): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        $usuario->desativar();
        $this->repository->salvar($usuario);
    }

    public function ativar(string $uuid): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);

        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }

        $usuario->ativar();
        $this->repository->salvar($usuario);
    }

    public function deletar(string $uuid): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        $this->repository->deletar($uuid);
    }

    /**
     * Lista usernames ativos para sitemap
     *
     * @return array<int, array{username: string, atualizado_em: ?string, criado_em: ?string}>
     */
    public function listarUsernamesAtivosParaSitemap(int $limite = 50000, int $offset = 0): array
    {
        return $this->repository->listarUsernamesAtivos($limite, $offset);
    }

}
