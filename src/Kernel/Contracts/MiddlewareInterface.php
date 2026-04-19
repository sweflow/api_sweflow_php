<?php

declare(strict_types=1);

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
