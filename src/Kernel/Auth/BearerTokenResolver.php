<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenResolverInterface;
use Src\Kernel\Http\Request\Request;

/**
 * Resolve token do header Authorization: Bearer ou X-API-KEY.
 *
 * Lê primeiro do objeto Request (headers já parseados pelo RequestFactory),
 * com fallback para $_SERVER para compatibilidade com contextos sem Request completo.
 */
final class BearerTokenResolver implements TokenResolverInterface
{
    private const MAX_TOKEN_LENGTH = 2048;

    public function resolve(Request $request): string
    {
        // 1. X-API-KEY do Request
        $token = $this->extractApiKey($request->header('X-API-KEY') ?? '');
        if ($token !== '') return $token;

        // 2. Authorization: Bearer do Request
        $token = $this->extractBearer($request->header('Authorization') ?? '');
        if ($token !== '') return $token;

        // 3. Fallback $_SERVER — cobre RequestFactory::fromGlobals() onde
        //    headers HTTP ficam em $_SERVER com prefixo HTTP_
        $token = $this->extractBearer($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($token !== '') return $token;

        return $this->extractApiKey($_SERVER['HTTP_X_API_KEY'] ?? '');
    }

    private function extractBearer(string $header): string
    {
        if ($header === '' || !str_contains($header, 'Bearer')) {
            return '';
        }
        if (!preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return '';
        }
        $token = $m[1];
        return strlen($token) <= self::MAX_TOKEN_LENGTH ? $token : '';
    }

    private function extractApiKey(string $key): string
    {
        $key = trim($key);
        return ($key !== '' && strlen($key) <= self::MAX_TOKEN_LENGTH) ? $key : '';
    }
}
