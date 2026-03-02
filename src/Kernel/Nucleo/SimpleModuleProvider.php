<?php

namespace Src\Nucleo;

use Src\Contracts\ContainerInterface;
use Src\Contracts\ModuleProviderInterface;
use Src\Contracts\RouterInterface;

/**
 * Lightweight provider created from plain config arrays so modules stay minimal.
 */
class SimpleModuleProvider implements ModuleProviderInterface
{
    private string $name;
    private array $bindings;
    private array $routes;

    public function __construct(string $name, array $bindings = [], array $routes = [])
    {
        $this->name = $name;
        $this->bindings = $bindings;
        $this->routes = $routes;
    }

    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            $config['name'] ?? $name,
            $config['bindings'] ?? [],
            $config['routes'] ?? []
        );
    }

    public function boot(ContainerInterface $container): void
    {
        foreach ($this->bindings as $abstract => $concrete) {
            if (is_string($concrete)) {
                $container->bind($abstract, fn(ContainerInterface $c) => $c->make($concrete));
                continue;
            }

            if (is_callable($concrete)) {
                $container->bind($abstract, $concrete);
                continue;
            }
        }
    }

    public function registerRoutes(RouterInterface $router): void
    {
        foreach ($this->routes as $route) {
            $method = strtoupper($route['method'] ?? 'GET');
            $uri = $route['uri'] ?? '';
            $handler = $route['action'] ?? null;
            $middlewares = $route['middlewares'] ?? [];

            if (!$uri || !$handler) {
                continue;
            }

            $router->add($method, $uri, $handler, $middlewares);
        }
    }

    public function describe(): array
    {
        return [
            'name' => $this->name,
            'routes' => array_map(function ($route) {
                return [
                    'method' => strtoupper($route['method'] ?? 'GET'),
                    'uri' => $route['uri'] ?? '',
                    'protected' => !empty($route['middlewares']),
                    'tipo' => !empty($route['middlewares']) ? 'privada' : 'pública',
                ];
            }, $this->routes),
        ];
    }
}
