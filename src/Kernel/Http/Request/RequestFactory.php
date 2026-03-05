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
            $json = json_decode($rawBody ?: '', true);
            if (is_array($json)) {
                $body = $json;
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
