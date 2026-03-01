<?php

namespace src\Http\Response;

class Response
{
    private int $status;
    private array $headers = [];
    private $body;

    public function __construct($body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function json($data, int $status = 200): self
    {
        $origin = self::resolveOrigin();
        $allowCredentials = $origin !== '*';
        $securityHeaders = self::securityHeaders();
        return new self(
            $data,
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Credentials' => $allowCredentials ? 'true' : 'false',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-CSRF-Token, X-Device-Id, X-Client-Public-IP',
                'Vary' => 'Origin'
            ] + $securityHeaders
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        $securityHeaders = self::securityHeaders();
        return new self(
            $html,
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8'
            ]
            + $securityHeaders
        );
    }

    private static function resolveOrigin(): string
    {
        $allowed = self::allowedOrigins();
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($requestOrigin && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return $allowed[0] ?? '*';
    }

    private static function allowedOrigins(): array
    {
        $origins = [];
        $frontend = $_ENV['APP_URL_FRONTEND'] ?? null;
        $backend = $_ENV['APP_URL'] ?? null;
        if ($frontend) {
            $origins[] = $frontend;
        }
        if ($backend && $backend !== $frontend) {
            $origins[] = $backend;
        }
        return array_values(array_filter($origins));
    }

    private static function securityHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()'
        ];

        $appUrl = $_ENV['APP_URL'] ?? '';
        $appUrlIsHttps = $appUrl !== '' && strncmp($appUrl, 'https://', 8) === 0;
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || $appUrlIsHttps;

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $headers;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setHeader(string $name, $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setBody($body): self
    {
        $this->body = $body;
        return $this;
    }

    public function Enviar(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        header("$name: $item", false);
                    }
                } else {
                    header("$name: $value", false);
                }
            }
        }

        // Always output the body, regardless of Content-Type header
        if (is_array($this->body) || is_object($this->body)) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
        } else {
            echo is_string($this->body) ? $this->body : json_encode($this->body, JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get response status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * Get response body
     *
     * @return mixed
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Get response headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

}
