<?php

namespace Src\Kernel\Contracts;

/**
 * Contrato mínimo que o Kernel precisa para autenticar usuários.
 * Os módulos implementam este contrato — o Kernel não depende de módulos.
 */
interface UserRepositoryInterface
{
    public function buscarPorUuid(string $uuid): ?object;
    public function buscarPorEmail(string $email): ?object;
    public function buscarPorUsername(string $username): ?object;

    /** Marca ou desmarca o e-mail como verificado. */
    public function marcarEmailComoVerificado(string $uuid, bool $verificado = true): void;

    /** Salva token de recuperação de senha. */
    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void;

    /** Busca usuário pelo token de recuperação de senha. */
    public function buscarPorTokenRecuperacaoSenha(string $token): ?object;

    /** Remove o token de recuperação de senha. */
    public function limparTokenRecuperacaoSenha(string $uuid): void;

    /** Salva token de verificação de e-mail. */
    public function salvarTokenVerificacaoEmail(string $uuid, string $token): void;

    /** Busca usuário pelo token de verificação de e-mail. */
    public function buscarPorTokenVerificacaoEmail(string $token): ?object;

    /** Persiste o usuário. */
    public function salvar(object $usuario): void;
}
