<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Garante que todos os headers de segurança estejam presentes em toda resposta.
 * Complementa os headers já definidos em Response::securityHeaders(),
 * cobrindo respostas que escapem do pipeline normal.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $appUrl  = $_ENV['APP_URL'] ?? '';
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || strncmp($appUrl, 'https://', 8) === 0;

        $isApi = str_starts_with($request->getUri(), '/api/');
        if ($isApi) {
            $csp = "default-src 'none'; frame-ancestors 'none'";
        } else {
            $nonce = \Src\Kernel\Nonce::get();
            $csp   = "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
        }

        $response = $response
            ->withHeader('X-Content-Type-Options',  'nosniff')
            ->withHeader('X-Frame-Options',          'DENY')
            ->withHeader('X-XSS-Protection',         '1; mode=block')
            ->withHeader('Referrer-Policy',          'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy',       'geolocation=(), microphone=(), camera=()')
            ->withHeader('Content-Security-Policy',  $csp);

        if ($isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
