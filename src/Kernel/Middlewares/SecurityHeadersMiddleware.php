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

        $isApi   = str_starts_with($request->getUri(), '/api/');
        $isHttps = CookieConfig::isHttps();

        $headers = Response::buildSecurityHeaders($isApi, $isHttps);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
