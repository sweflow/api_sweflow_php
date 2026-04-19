<?php

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

interface RouterInterface
{
    /** @param array<int|string, mixed> $middlewares */
    public function get(string $uri, mixed $handler, array $middlewares = []): void;

    /** @param array<int|string, mixed> $middlewares */
    public function post(string $uri, mixed $handler, array $middlewares = []): void;

    /** @param array<int|string, mixed> $middlewares */
    public function put(string $uri, mixed $handler, array $middlewares = []): void;

    /** @param array<int|string, mixed> $middlewares */
    public function patch(string $uri, mixed $handler, array $middlewares = []): void;

    /** @param array<int|string, mixed> $middlewares */
    public function delete(string $uri, mixed $handler, array $middlewares = []): void;

    /** @param array<int|string, mixed> $middlewares */
    public function add(string $method, string $uri, mixed $handler, array $middlewares = []): void;

    public function dispatch(Request $request): Response;

    /** @return array<int, array<string, mixed>> */
    public function all(): array;
}
