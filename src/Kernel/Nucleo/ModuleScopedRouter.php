<?php

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Router decorator that injects a module-enabled guard into all routes.
 */
class ModuleScopedRouter implements RouterInterface
{
    private RouterInterface $router;
    private ModuleLoader $modules;
    private string $module;
    private array $registeredRoutes = [];

    public function __construct(RouterInterface $router, ModuleLoader $modules, string $module)
    {
        $this->router = $router;
        $this->modules = $modules;
        $this->module = $module;
    }

    public function get(string $uri, mixed $handler, array $middlewares = []): void
    {
        $this->add('GET', $uri, $handler, $middlewares);
    }

    public function post(string $uri, mixed $handler, array $middlewares = []): void
    {
        $this->add('POST', $uri, $handler, $middlewares);
    }

    public function put(string $uri, mixed $handler, array $middlewares = []): void
    {
        $this->add('PUT', $uri, $handler, $middlewares);
    }

    public function patch(string $uri, mixed $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $uri, $handler, $middlewares);
    }

    public function delete(string $uri, mixed $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $uri, $handler, $middlewares);
    }

    public function add(string $method, string $uri, mixed $handler, array $middlewares = []): void
    {
        $this->router->add($method, $uri, $handler, $this->withGuard($middlewares));
        // Guarda os middlewares ORIGINAIS (sem o guard interno do módulo)
        // para que o describe() avalie corretamente quais rotas são públicas/privadas
        $this->registeredRoutes[] = [
            'method'      => $method,
            'uri'         => $uri,
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function getRegisteredRoutes(): array
    {
        return $this->registeredRoutes;
    }

    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    public function all(): array
    {
        return $this->router->all();
    }

    private function withGuard(array $middlewares): array
    {
        $guard = function ($request, $next) {
            if (!$this->modules->isEnabled($this->module)) {
                return Response::json(['error' => 'Módulo desabilitado'], 404);
            }
            return $next($request);
        };

        // Coloca o guard no fim para que ele rode primeiro na pipeline (middlewares são invertidos no dispatch)
        return array_merge($middlewares, [$guard]);
    }
}
