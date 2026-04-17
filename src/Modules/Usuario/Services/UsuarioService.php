<?php

namespace Src\Modules\Usuario\Services;

use Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface;
use Src\Modules\Usuario\Entities\Usuario;
use DomainException;

class UsuarioService implements UsuarioServiceInterface
{
    public function __construct(
        private UsuarioRepositoryInterface $repository
    ) {}

    public function emailExiste(string $email): bool
    {
        return $this->repository->emailExiste($email);
    }

    public function usernameExiste(string $username): bool
    {
        return $this->repository->usernameExiste($username);
    }

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

        // Valida unicidade antes de aplicar mudanças na entity
        $emailAlvo    = $data['email']    ?? $usuario->getEmail();
        $usernameAlvo = $data['username'] ?? $usuario->getUsername();

        if ($this->repository->emailExiste($emailAlvo, $uuid)) {
            throw new DomainException('E-mail já cadastrado.');
        }
        if ($this->repository->usernameExiste($usernameAlvo, $uuid)) {
            throw new DomainException('Username já cadastrado.');
        }

        // Aplica mudanças via métodos de domínio (com validação interna)
        if (isset($data['nome_completo'])) {
            $usuario->alterarNomeCompleto($data['nome_completo']);
        }
        if (isset($data['username'])) {
            $usuario->alterarUsername($data['username']);
        }
        if (isset($data['email'])) {
            $usuario->alterarEmail($data['email']);
        }
        if (isset($data['senha'])) {
            $usuario->alterarSenha($data['senha']);
        }
        if (isset($data['url_avatar'])) {
            $usuario->alterarAvatar($data['url_avatar'] ?: null);
        }
        if (isset($data['url_capa'])) {
            $usuario->alterarCapa($data['url_capa'] ?: null);
        }
        if (isset($data['biografia'])) {
            $usuario->alterarBiografia($data['biografia'] ?: null);
        }
        if (isset($data['nivel_acesso'])) {
            $usuario->promoverPara($data['nivel_acesso']);
        }

        $this->repository->salvar($usuario);
    }

    // Salva o token de verificação de e-mail
    public function salvarTokenVerificacaoEmail(string $uuid, string $token): void
    {
        // Persiste diretamente — evita busca desnecessária só para atualizar um campo
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
        // Persiste diretamente — evita busca desnecessária só para atualizar um campo
        $this->repository->salvarTokenRecuperacaoSenha($uuid, $token);
    }

    public function limparTokenRecuperacaoSenha(string $uuid): void
    {
        $this->repository->limparTokenRecuperacaoSenha($uuid);
    }

    // Marca o e-mail como verificado
    public function marcarEmailComoVerificado(string $uuid): void
    {
        // Persiste diretamente — a lógica de domínio (limpar token, atualizar status)
        // está encapsulada na query do repositório para evitar busca extra desnecessária
        $this->repository->marcarEmailComoVerificado($uuid);
    }

    // Reseta a verificação de e-mail (usado ao alterar o endereço de e-mail)
    public function resetarVerificacaoEmail(string $uuid): void
    {
        $this->repository->marcarEmailComoVerificado($uuid, false);
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
        return $usuario->verificarSenha($senha);
    }

    /**
     * Altera a senha do usuário.
     */
    public function alterarSenha(string $uuid, string $novaSenha): void
    {
        $usuario = $this->repository->buscarPorUuid($uuid);
        if (!$usuario) {
            throw new DomainException('Usuário não encontrado.');
        }
        $usuario->alterarSenha($novaSenha);
        $this->repository->salvar($usuario);
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
        return $this->repository->buscarTodos($porPagina, ($pagina - 1) * $porPagina);
    }

    public function listarComFiltro(int $pagina, int $porPagina, string $busca = '', string $nivel = ''): array
    {
        return $this->repository->buscarComFiltro($pagina, $porPagina, $busca, $nivel);
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
