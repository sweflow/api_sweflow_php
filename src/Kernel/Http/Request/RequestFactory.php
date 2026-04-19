<?php

namespace Src\Kernel\Http\Request;

class RequestFactory
{
    public static function fromGlobals(): Request
    {
        $headers = self::headers();
        $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Lê o body uma única vez e armazena no Request — elimina $GLOBALS['__raw_input']
        $rawBody = (string) (file_get_contents('php://input') ?: '');

        // Limite de payload configurável via MAX_PAYLOAD_KB (padrão: 64KB para API, 10MB para uploads)
        $maxKb      = (int) ($_ENV['MAX_PAYLOAD_KB'] ?? getenv('MAX_PAYLOAD_KB') ?: 64);
        $maxBytes   = max(1, $maxKb) * 1024;
        if (strlen($rawBody) > $maxBytes) {
            $response = new \Src\Kernel\Http\Response\Response(
                ['status' => 'error', 'message' => "Payload muito grande. Limite: {$maxKb}KB."],
                413,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
            $response->send();
            exit;
        }

        $body        = $_POST;
        $contentType = $headers['Content-Type'] ?? ($headers['content-type'] ?? '');

        if (stripos($contentType, 'application/json') !== false && $rawBody !== '') {
            $json = json_decode($rawBody, true, 16); // profundidade 16 — previne DoS via JSON profundo
            if (is_array($json)) {
                $body = $json;
            } elseif ($json !== null) {
                // JSON válido mas não é objeto/array — ignora
                $body = [];
            }
        } elseif (stripos($contentType, 'application/x-www-form-urlencoded') !== false && $rawBody !== '') {
            parse_str($rawBody, $formData);
            $body = array_merge($_POST, $formData);
        }

        return new Request($body, $_GET, $headers, $method, $uri, $rawBody, $_COOKIE);
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
