<?php

namespace Src\Kernel\Http\Request;

class RequestFactory
{
    public static function fromGlobals(): Request
    {
        $headers = self::headers();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $rawBody = $GLOBALS['__raw_input'] ?? file_get_contents('php://input');
        $body = $_POST;

        $contentType = $headers['Content-Type'] ?? ($headers['content-type'] ?? '');
        if (stripos($contentType, 'application/json') !== false) {
            // Limita profundidade e tamanho para evitar DoS
            if (is_string($rawBody) && strlen($rawBody) <= 2 * 1024 * 1024) {
                $json = json_decode($rawBody ?: '', true, 32);
                if (is_array($json)) {
                    // Sanitiza valores não-escalares para evitar TypeError nos controllers
                    array_walk_recursive($json, function (&$v) {
                        if (!is_scalar($v) && $v !== null) { $v = ''; }
                    });
                    $body = $json;
                }
            }
        }

        return new Request(
            $body,
            $_GET ?? [],
            $headers,
            $method,
            $uri,
            $rawBody
        );
    }

    private static function headers(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
}
