<?php

namespace Src\Kernel;

/**
 * Gera e armazena um nonce CSP único por request.
 * Usado para permitir <script nonce="..."> e <style nonce="..."> inline
 * sem precisar de 'unsafe-inline'.
 */
class Nonce
{
    private static ?string $value = null;

    public static function get(): string
    {
        if (self::$value === null) {
            self::$value = base64_encode(random_bytes(16));
        }
        return self::$value;
    }

    public static function reset(): void
    {
        self::$value = null;
    }
}
