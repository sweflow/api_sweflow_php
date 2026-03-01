<?php

namespace src\Modules\Usuario\Services;

// Removido import duplicado
use src\Modules\Usuario\Entities\Usuario;

interface UsuarioServiceInterface
{
    public function emailExiste(string $email): bool;
    public function usernameExiste(string $username): bool;
    public function criar(Usuario $usuario): void;

    public function atualizar(string $uuid, array $dados): void;

    public function buscarPorUuid(string $uuid): ?Usuario;

    public function buscarPorUsername(string $username): ?Usuario;

    public function buscarPorEmail(string $email): ?Usuario;

    /**
     * Salva o token de verificação de e-mail para o usuário
     */
    public function salvarTokenVerificacaoEmail(string $uuid, string $token): void;

    /**
     * Busca usuário pelo token de verificação de e-mail
     */
    public function buscarPorTokenVerificacaoEmail(string $token): ?Usuario;

    /**
     * Busca usuário pelo token de recuperação de senha
     */
    public function buscarPorTokenRecuperacaoSenha(string $token): ?Usuario;

    /**
     * Salva o token de recuperação de senha para o usuário
     */
    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void;

    /**
     * Remove o token de recuperação de senha do usuário
     */
    public function limparTokenRecuperacaoSenha(string $uuid): void;

    /**
     * Marca o e-mail do usuário como verificado
     */
    public function marcarEmailComoVerificado(string $uuid): void;

     /**
     * Verifica se a senha fornecida está correta para o usuário.
     */
    public function verificarSenha(string $uuid, string $senha): bool;

    /**
     * Altera a senha do usuário.
     */
    public function alterarSenha(string $uuid, string $novaSenha, bool $logoutAll = false): void;

    /**
     * @return Usuario[]
     */
    public function listar(int $pagina = 1, int $porPagina = 20): array;

    public function desativar(string $uuid): void;

    public function ativar(string $uuid): void;

    public function deletar(string $uuid): void;

    /**
     * Lista usernames ativos para sitemap
     *
     * @return array<int, array{username: string, atualizado_em: ?string, criado_em: ?string}>
     */
    public function listarUsernamesAtivosParaSitemap(int $limite = 50000, int $offset = 0): array;

}
