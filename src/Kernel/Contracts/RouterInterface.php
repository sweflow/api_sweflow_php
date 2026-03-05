<?php

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

interface RouterInterface
{
    public function get(string $uri, $handler, array $middlewares = []): void;
    public function post(string $uri, $handler, array $middlewares = []): void;
    public function put(string $uri, $handler, array $middlewares = []): void;
    public function patch(string $uri, $handler, array $middlewares = []): void;
    public function delete(string $uri, $handler, array $middlewares = []): void;
    public function add(string $method, string $uri, $handler, array $middlewares = []): void;
    public function dispatch(Request $request): Response;
    public function all(): array;
}
