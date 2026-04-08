<?php

namespace Src\Kernel\Http\Response;

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
        // Headers de segurança são adicionados aqui como fallback para respostas que
        // escapem do SecurityHeadersMiddleware (ex: erros de roteamento, 404, etc).
        // O middleware sobrescreve esses headers via withHeader() quando presente.
        $securityHeaders = self::securityHeaders();

        $headers = ['Content-Type' => 'application/json; charset=utf-8'] + $securityHeaders;

        // Vary: Origin deve estar sempre presente em respostas JSON para que proxies/CDNs
        // não sirvam respostas sem CORS para clientes que precisam delas.
        $headers['Vary'] = 'Origin';

        // Só emite headers CORS quando há uma origem cross-origin válida
        if ($origin !== '') {
            $headers['Access-Control-Allow-Origin']      = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Access-Control-Allow-Methods']     = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
            $headers['Access-Control-Allow-Headers']     = 'Content-Type, Authorization, X-CSRF-Token, X-Device-Id, X-Client-Public-IP';
        }

        return new self($data, $status, $headers);
    }

    public static function html(string $html, int $status = 200): self
    {
        $securityHeaders = self::securityHeaders(false);
        return new self(
            $html,
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'] + $securityHeaders
        );
    }

    private static function resolveOrigin(): string
    {
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Sem header Origin = requisição direta (não cross-origin) — não emite CORS
        if ($requestOrigin === '') {
            return '';
        }

        $allowed = self::allowedOrigins();
        if (in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        // Origem não permitida — retorna vazio para bloquear CORS
        return '';
    }

    private static function allowedOrigins(): array
    {
        $origins = [];

        // CORS_ALLOWED_ORIGINS aceita lista separada por vírgula
        $extra = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
        if ($extra !== '') {
            foreach (explode(',', $extra) as $o) {
                $o = trim($o);
                if ($o !== '') $origins[] = $o;
            }
        }

        $frontend = $_ENV['APP_URL_FRONTEND'] ?? null;
        $backend  = $_ENV['APP_URL']          ?? null;
        if ($frontend && !in_array($frontend, $origins, true)) $origins[] = $frontend;
        if ($backend  && !in_array($backend,  $origins, true)) $origins[] = $backend;

        return array_values(array_filter($origins));
    }

    private static function securityHeaders(bool $isApi = true): array
    {
        return self::buildSecurityHeaders($isApi, \Src\Kernel\Support\CookieConfig::isHttps());
    }

    /**
     * Constrói os headers de segurança.
     * Público para permitir reuso em SecurityHeadersMiddleware sem duplicar a lógica.
     */
    public static function buildSecurityHeaders(bool $isApi = true, bool $isHttps = false): array
    {
        if ($isApi) {
            // API pura: política máxima — nenhum recurso permitido, base-uri none (sem <base> tag em JSON)
            $csp = "default-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'";
        } else {
            $nonce = \Src\Kernel\Nonce::get();
            // Página HTML: scripts via nonce + SRI para CDN externo.
            // SRI (Subresource Integrity) garante que scripts externos não foram adulterados.
            // require-sri-for bloqueia scripts/styles sem integrity attribute.
            // Trusted Types: mata DOM XSS moderno (Chrome/Edge 83+).
            $csp = "default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; style-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self' https://cdnjs.cloudflare.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; require-trusted-types-for 'script'; trusted-types default dompurify";
        }

        $headers = [
            'X-Content-Type-Options'          => 'nosniff',
            'X-Frame-Options'                 => 'DENY',
            // X-XSS-Protection removido: deprecated desde 2019, ignorado por browsers modernos
            // e pode causar vulnerabilidades em browsers legados. O CSP cobre XSS adequadamente.
            // API usa no-referrer — não há motivo para enviar referrer em chamadas de API
            // HTML usa strict-origin-when-cross-origin — melhor equilíbrio para páginas
            'Referrer-Policy'                 => $isApi ? 'no-referrer' : 'strict-origin-when-cross-origin',
            'Permissions-Policy'              => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy'         => $csp,
            // CORP/COEP/COOP: isolamento de processo — protege contra Spectre/XS-Leaks
            // COEP usa 'credentialless' em vez de 'require-corp' para permitir CDNs externos
            // sem exigir que eles enviem Cross-Origin-Resource-Policy: cross-origin
            'Cross-Origin-Resource-Policy'    => $isApi ? 'same-origin' : 'same-site',
            'Cross-Origin-Opener-Policy'      => 'same-origin',
            'Cross-Origin-Embedder-Policy'    => $isApi ? 'require-corp' : 'credentialless',
        ];

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $headers;
    }

    /** Retorna nova instância com header adicionado (imutável). */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** Retorna nova instância com múltiplos headers adicionados (imutável). */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[$name] = $value;
        }
        return $clone;
    }

    public function send(): void
    {
        // Descarta qualquer output espúrio (warnings, notices) capturado pelo ob_start() do index.php
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

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

        if (is_array($this->body) || is_object($this->body)) {
            try {
                echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                echo json_encode(['error' => 'Erro interno ao serializar resposta.']);
            }
        } else {
            echo is_string($this->body) ? $this->body : (string) json_encode($this->body, JSON_UNESCAPED_UNICODE);
        }
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
