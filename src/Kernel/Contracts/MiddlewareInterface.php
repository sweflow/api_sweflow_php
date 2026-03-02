<?php

namespace Src\Contracts;

use Src\Http\Request\Request;
use Src\Http\Response\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
