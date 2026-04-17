<?php

namespace Src\Kernel\Contracts;

/**
 * Contrato que qualquer entidade autenticável deve implementar.
 *
 * Permite que o módulo Auth funcione com qualquer tabela/banco de dados,
 * sem depender da entidade Usuario do módulo Usuario.
 *
 * Para tornar uma entidade autenticável, implemente esta interface:
 *
 *   class Cliente implements AuthenticatableInterface { ... }
 *
 * E registre no container:
 *
 *   $container->bind(
 *       UserRepositoryInterface::class,
 *       fn() => new ClienteRepository($pdo),
 *       true
 *   );
 */
interface AuthenticatableInterface
{
    /** Identificador único imutável (UUID ou equivalente). */
    public function getAuthId(): string;

    /** E-mail do usuário — usado no payload do JWT e no login. */
    public function getAuthEmail(): string;

    /** Identificador de login alternativo (username, CPF, etc.). Pode retornar null. */
    public function getAuthUsername(): ?string;

    /** Nível de acesso — ex: 'usuario', 'admin', 'admin_system'. */
    public function getAuthRole(): string;

    /** Verifica se a senha em texto plano corresponde ao hash armazenado. */
    public function verificarSenha(string $senhaPlana): bool;

    /** Retorna false se a conta estiver desativada/bloqueada. */
    public function isAtivo(): bool;

    /** Retorna true se o e-mail foi verificado. */
    public function isEmailVerificado(): bool;

    /**
     * Retorna o timestamp Unix da última troca de senha, ou null se não rastreado.
     * Usado para invalidar tokens emitidos antes da troca de senha.
     */
    public function getSenhaAlteradaEm(): ?int;
}
