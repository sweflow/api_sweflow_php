<?php

declare(strict_types=1);

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\AuthContextInterface;
use Src\Kernel\Contracts\AuthIdentityInterface;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

final class OptionalAuthHybridMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?AuthContextInterface $auth) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth !== null) {
            $identity = $this->auth->resolve($request);
            if ($this->isUsable($identity)) {
                $request = $request
                    ->withAttribute(AuthContextInterface::IDENTITY_KEY, $identity)
                    ->withAttribute(AuthContextInterface::LEGACY_USER_KEY, $identity->user())
                    ->withAttribute(AuthContextInterface::LEGACY_PAYLOAD_KEY, $identity->payload());
            }
        }

        return $next($request);
    }

    private function isUsable(?AuthIdentityInterface $identity): bool
    {
        if ($identity === null) {
            return false;
        }
        $type = $identity->type();
        return $type !== 'inactive' && $type !== 'not_found';
    }
}
