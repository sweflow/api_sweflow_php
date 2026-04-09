<?php

namespace Src\Kernel\Utils;

/**
 * Sanitização e validação de inputs de entrada.
 * Centraliza regras para evitar duplicação e garantir consistência.
 */
final class Sanitizer
{
    /**
     * String simples: remove espaços extras, tags HTML e caracteres de controle.
     */
    public static function string(mixed $value, int $maxLen = 255): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = (string) ($value ?? '');
        $s = strip_tags($s);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = trim($s);
        return mb_substr($s, 0, $maxLen);
    }

    /**
     * E-mail: lowercase, trim, valida formato.
     * Retorna string vazia se inválido.
     */
    public static function email(mixed $value): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = strtolower(trim((string) ($value ?? '')));
        return filter_var($s, FILTER_VALIDATE_EMAIL) !== false ? $s : '';
    }

    /**
     * Username: lowercase, apenas letras, números, ponto e underline.
     */
    public static function username(mixed $value, int $maxLen = 50): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = strtolower(trim((string) ($value ?? '')));
        $s = (string) preg_replace('/[^a-z0-9._]/', '', $s);
        return mb_substr($s, 0, $maxLen);
    }

    /**
     * Inteiro positivo com valor mínimo e máximo.
     */
    public static function positiveInt(mixed $value, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $n = (int) ($value ?? $min);
        return max($min, min($max, $n));
    }

    /**
     * Nível de acesso: whitelist estrita.
     */
    public static function nivelAcesso(mixed $value): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $allowed = ['usuario', 'moderador', 'admin', 'admin_system'];
        $s = strtolower(trim((string) ($value ?? '')));
        return in_array($s, $allowed, true) ? $s : '';
    }

    /**
     * UUID v4: valida formato.
     * Retorna string vazia se inválido.
     */
    public static function uuid(mixed $value): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = trim((string) ($value ?? ''));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $s)
            ? strtolower($s)
            : '';
    }

    /**
     * Busca livre: remove caracteres perigosos, limita tamanho.
     */
    public static function search(mixed $value, int $maxLen = 100): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = (string) ($value ?? '');
        $s = strip_tags($s);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $s);
        $s = trim($s);
        return mb_substr($s, 0, $maxLen);
    }

    /**
     * URL: valida esquema http/https, retorna vazio se inválida.
     */
    public static function url(mixed $value, int $maxLen = 500): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return '';
        }
        $s         = mb_substr($s, 0, $maxLen);
        $validated = filter_var($s, FILTER_VALIDATE_URL);
        if ($validated === false) {
            return '';
        }
        $scheme = strtolower((string) (parse_url($validated, PHP_URL_SCHEME) ?? ''));
        return in_array($scheme, ['http', 'https'], true) ? $validated : '';
    }

    /**
     * Texto longo (biografia, descrição): remove tags, limita tamanho.
     */
    public static function text(mixed $value, int $maxLen = 1000): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = strip_tags((string) ($value ?? ''));
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        return mb_substr(trim($s), 0, $maxLen);
    }

    /**
     * Senha: apenas verifica que não está vazia e não excede tamanho máximo.
     * Não faz strip_tags — senhas podem conter qualquer caractere.
     * Remove null bytes que podem causar comportamento inesperado em funções C.
     */
    public static function password(mixed $value, int $maxLen = 128): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        $s = (string) ($value ?? '');
        // Remove null bytes
        $s = str_replace("\x00", '', $s);
        return mb_substr($s, 0, $maxLen);
    }
}
