<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\CookieConfig;

/**
 * Bloqueia qualquer requisição HTTP quando COOKIE_SECURE=true e COOKIE_HTTPONLY=true.
 * Retorna 403 com mensagem clara para APIs e página HTML para browsers.
 */
class HttpsEnforcerMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!CookieConfig::requiresHttps() || CookieConfig::isHttps()) {
            return $next($request);
        }

        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        // Requisições de API ou JSON recebem resposta JSON
        if (
            str_contains($accept, 'application/json') ||
            str_starts_with($uri, '/api/')
        ) {
            return Response::json([
                'status'  => 'error',
                'message' => 'Esta API requer HTTPS. O uso de HTTP não é permitido por razões de segurança.',
                'code'    => 'HTTP_NOT_ALLOWED',
            ], 403);
        }

        // Browsers recebem página HTML
        $html = $this->renderHtmlPage();
        return Response::html($html, 403);
    }

    private function renderHtmlPage(): string
    {
        $host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'este servidor', ENT_QUOTES, 'UTF-8');
        $uri  = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8');
        $httpsUrl = 'https://' . $host . $uri;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>HTTPS Obrigatório</title>
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                    background: #0f172a;
                    color: #f1f5f9;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                }
                .card {
                    background: #1e293b;
                    border: 1px solid #334155;
                    border-radius: 16px;
                    padding: 48px 40px;
                    max-width: 520px;
                    width: 100%;
                    text-align: center;
                }
                .icon {
                    font-size: 3rem;
                    margin-bottom: 20px;
                }
                h1 {
                    font-size: 1.5rem;
                    font-weight: 700;
                    color: #f87171;
                    margin-bottom: 12px;
                }
                p {
                    font-size: 0.97rem;
                    color: #94a3b8;
                    line-height: 1.7;
                    margin-bottom: 28px;
                }
                a {
                    display: inline-block;
                    background: #4f46e5;
                    color: #fff;
                    text-decoration: none;
                    padding: 12px 28px;
                    border-radius: 10px;
                    font-weight: 600;
                    font-size: 0.95rem;
                    transition: background .15s;
                }
                a:hover { background: #4338ca; }
                .badge {
                    display: inline-block;
                    background: rgba(248,113,113,0.12);
                    color: #f87171;
                    border: 1px solid rgba(248,113,113,0.25);
                    border-radius: 999px;
                    font-size: 0.78rem;
                    font-weight: 700;
                    padding: 4px 14px;
                    margin-bottom: 20px;
                    letter-spacing: 0.5px;
                    text-transform: uppercase;
                }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">🔒</div>
                <div class="badge">HTTP não permitido</div>
                <h1>Conexão segura obrigatória</h1>
                <p>
                    Este servidor está configurado para aceitar apenas conexões <strong>HTTPS</strong>.<br>
                    O uso de HTTP não é permitido por razões de segurança — seus dados precisam ser protegidos em trânsito.
                </p>
                <a href="{$httpsUrl}">Acessar via HTTPS →</a>
            </div>
        </body>
        </html>
        HTML;
    }
}
