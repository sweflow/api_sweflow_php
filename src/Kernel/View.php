<?php
namespace Src\Kernel;

class View
{
    public static function render(string $view, array $data = []): void
    {
        extract($data);
        if (!empty($view)) {
            $caminho = __DIR__ . "/Views/{$view}.php";
            if (file_exists($caminho)) {
                include $caminho;
            } else {
                echo "<b>Erro:</b> View '{$view}' não encontrada em 'src/Views/'.";
            }
        }
    }

    /**
     * Retorna os headers de segurança para páginas HTML.
     * Usado por Response::html() para aplicar os headers corretos.
     */
    public static function securityHeaders(): array
    {
        $nonce = \Src\Kernel\Nonce::get();
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];

        if (\Src\Kernel\Support\CookieConfig::isHttps()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $headers;
    }
}
