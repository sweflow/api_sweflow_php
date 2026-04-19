<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

/**
 * Objeto de identidade padronizado — fonte única de verdade sobre quem está autenticado.
 *
 * IMUTÁVEL por contrato: implementações não devem expor setters.
 *
 * Métodos tipados (preferir sempre):
 *   id(), role(), type(), hasRole(), isAuthenticated(), isApiToken(), isGuest()
 *
 * Escape hatches (low-level, evitar no código de negócio):
 *   user()    → objeto de usuário bruto
 *   payload() → TokenPayloadInterface bruto
 */
interface AuthIdentityInterface
{
    // ── Identificação ─────────────────────────────────────────────────

    /**
     * ID do usuário autenticado (UUID, int, etc.).
     * Retorna null para tokens de API puro.
     */
    public function id(): string|int|null;

    /**
     * Papel/nível de acesso da identidade.
     * Retorna null para tokens de API puro ou quando não há role definida.
     */
    public function role(): ?string;

    /**
     * Tipo da identidade — permite multi-auth sem instanceof.
     *
     * Valores padrão:
     *   'user'      → usuário humano autenticado
     *   'api_token' → token machine-to-machine
     *   'guest'     → não autenticado
     *   'inactive'  → credencial válida, conta inativa
     *
     * Módulos podem definir tipos adicionais: 'service', 'bot', 'impersonated', etc.
     */
    public function type(): string;

    // ── Verificações de estado ────────────────────────────────────────

    /** Usuário humano autenticado e ativo. */
    public function isAuthenticated(): bool;

    /** Token machine-to-machine sem usuário. */
    public function isApiToken(): bool;

    /** Sem credencial válida. */
    public function isGuest(): bool;

    /**
     * Verifica se a identidade possui um dos papéis informados.
     * Delega para AuthorizationInterface quando disponível.
     * Fallback heurístico quando não há authorization registrado.
     */
    public function hasRole(string ...$roles): bool;

    // ── Escape hatches (low-level) ────────────────────────────────────

    /**
     * Objeto de usuário bruto.
     * Preferir id() e role() para código de negócio.
     */
    public function user(): mixed;

    /**
     * Payload tipado do token.
     * Preferir id(), role() e get() para código de negócio.
     * Retorna null para tokens de API puro e identidades sem payload.
     */
    public function payload(): ?TokenPayloadInterface;
}
