<?php

namespace src\Modules\Usuario\Repositories;

use src\Modules\Usuario\Entities\Usuario;

interface UsuarioRepositoryInterface
{
    /**
     * Retorna usuários paginados
     * 
     * @return Usuario[]
     */
    public function buscarTodos(int $limite = 100, int $offset = 0): array;

    /**
     * Busca um usuário pelo UUID
     */
    public function buscarPorUuid(string $uuid): ?Usuario;

    /**
     * Busca um usuário pelo username
     */
    public function buscarPorUsername(string $username): ?Usuario;

    /**
     * Busca um usuário pelo e-mail
     */
    public function buscarPorEmail(string $email): ?Usuario;

    /**
     * Salva o token de recuperação de senha para o usuário
     */
    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void;

    /**
     * Busca usuário pelo token de recuperação de senha
     */
    public function buscarPorTokenRecuperacaoSenha(string $token): ?Usuario;

    /**
     * Remove o token de recuperação de senha do usuário
     */
    public function limparTokenRecuperacaoSenha(string $uuid): void;

    /**
     * Salva o token de verificação de e-mail para o usuário
     */
    public function salvarTokenVerificacaoEmail(string $uuid, string $token): void;

    /**
     * Busca usuário pelo token de verificação de e-mail
     */
    public function buscarPorTokenVerificacaoEmail(string $token): ?Usuario;

    /**
     * Marca o e-mail do usuário como verificado
     */
    public function marcarEmailComoVerificado(string $uuid): void;

    /**
     * Verifica se e-mail já existe
     */
    public function emailExiste(string $email, ?string $excluirUuid = null): bool;

    /**
     * Verifica se username já existe
     */
    public function usernameExiste(string $username, ?string $excluirUuid = null): bool;

    /**
     * Salva (cria ou atualiza) um usuário
     */
    public function salvar(Usuario $usuario): void;

    /**
     * Remove um usuário pelo UUID
     */
    public function deletar(string $uuid): void;

    /**
     * Retorna total de usuários
     */
    public function contar(): int;

    /**
     * Busca usuários por nome com paginação
     * 
     * @return Usuario[]
     */
    public function buscarPorNomePaginado(
        string $nome,
        int $pagina = 1,
        int $porPagina = 10
    ): array;

    /**
     * Lista usernames ativos para sitemap
     *
     * @return array<int, array{username: string, atualizado_em: ?string, criado_em: ?string}>
     */
    public function listarUsernamesAtivos(int $limite = 50000, int $offset = 0): array;
}
