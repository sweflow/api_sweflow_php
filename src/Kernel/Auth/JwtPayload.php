<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenPayloadInterface;

/**
 * Wrapper tipado para o payload JWT (stdClass do firebase/php-jwt).
 * Imutável: construtor recebe o stdClass clonado.
 */
final class JwtPayload implements TokenPayloadInterface
{
    private readonly \stdClass $data;

    public function __construct(\stdClass $raw, private readonly bool $signedWithApiSecret)
    {
        $this->data = clone $raw;
    }

    public function getSubject(): ?string
    {
        $sub = $this->data->sub ?? null;
        return $sub !== null ? (string) $sub : null;
    }

    public function getRole(): ?string
    {
        $role = $this->data->nivel_acesso ?? $this->data->role ?? null;
        return $role !== null ? (string) $role : null;
    }

    public function isSignedWithApiSecret(): bool
    {
        return $this->signedWithApiSecret;
    }

    public function get(string $key): mixed
    {
        return $this->data->$key ?? null;
    }

    public function raw(): mixed
    {
        return clone $this->data;
    }
}
