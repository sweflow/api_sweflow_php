<?php

namespace Src\Kernel\Support;

/**
 * Utilitário para construção de URLs absolutas.
 * Centraliza a lógica de resolução da URL base da aplicação,
 * eliminando duplicação entre controllers.
 */
final class UrlHelper
{
    /**
     * Retorna a URL base do frontend (sem barra final).
     * Prioridade: APP_URL_FRONTEND → APP_URL → REQUEST_SCHEME + HTTP_HOST
     */
    public static function baseUrl(): string
    {
        $base = rtrim($_ENV['APP_URL_FRONTEND'] ?? $_ENV['APP_URL'] ?? '', '/');
        if ($base !== '') {
            return $base;
        }
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    /**
     * Constrói uma URL absoluta a partir de um caminho relativo.
     */
    public static function to(string $path): string
    {
        return self::baseUrl() . '/' . ltrim($path, '/');
    }
}
