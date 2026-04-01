<?php
namespace Src\Kernel;

class View
{
    public static function render(string $view, array $data = []): void
    {
        // Emite headers de segurança para páginas HTML antes de qualquer output
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            // CSP para páginas HTML com assets locais (JS/CSS/imagens do próprio domínio)
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

            $appUrl = $_ENV['APP_URL'] ?? '';
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || strncmp($appUrl, 'https://', 8) === 0;
            if ($isHttps) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            }
        }

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
}
