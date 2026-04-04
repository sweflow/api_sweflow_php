<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\CookieConfig;

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

        $isHttps = CookieConfig::isHttps();

        $isApi = str_starts_with($request->getUri(), '/api/');
        if ($isApi) {
            // API pura: base-uri 'none' — API não tem <base> tag, mais restritivo que 'self'
            $csp = "default-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'";
        } else {
            $nonce = \Src\Kernel\Nonce::get();
            // Trusted Types ativo — política 'default' em dashboard.js aceita HTML do próprio código
            $csp   = "default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; require-trusted-types-for 'script'; trusted-types default";
        }

        $response = $response
            ->withHeader('X-Content-Type-Options',       'nosniff')
            ->withHeader('X-Frame-Options',               'DENY')
            // X-XSS-Protection removido: deprecated, ignorado por browsers modernos,
            // pode causar vulnerabilidades em browsers legados. CSP cobre XSS.
            // API usa no-referrer — sem motivo para enviar referrer em chamadas de API
            ->withHeader('Referrer-Policy',               $isApi ? 'no-referrer' : 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy',            'geolocation=(), microphone=(), camera=()')
            ->withHeader('Content-Security-Policy',       $csp)
            ->withHeader('Cross-Origin-Resource-Policy',  'same-origin')
            ->withHeader('Cross-Origin-Opener-Policy',    'same-origin')
            ->withHeader('Cross-Origin-Embedder-Policy',  'require-corp');

        if ($isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
