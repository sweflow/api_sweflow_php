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
        $appUrl  = $_ENV['APP_URL'] ?? '';
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || strncmp($appUrl, 'https://', 8) === 0;

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $headers;
    }
}
