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

    public function __construct(RouterInterface $router, ModuleLoader $modules, string $module)
    {
        $this->router = $router;
        $this->modules = $modules;
        $this->module = $module;
    }

    public function get(string $uri, $handler, array $middlewares = []): void
    {
        $this->add('GET', $uri, $handler, $middlewares);
    }

    public function post(string $uri, $handler, array $middlewares = []): void
    {
        $this->add('POST', $uri, $handler, $middlewares);
    }

    public function put(string $uri, $handler, array $middlewares = []): void
    {
        $this->add('PUT', $uri, $handler, $middlewares);
    }

    public function patch(string $uri, $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $uri, $handler, $middlewares);
    }

    public function delete(string $uri, $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $uri, $handler, $middlewares);
    }

    public function add(string $method, string $uri, $handler, array $middlewares = []): void
    {
        $this->router->add($method, $uri, $handler, $this->withGuard($middlewares));
        // Guarda a rota no provider associado (se possível) ou expõe um evento.
        // Como não temos evento, vamos adicionar um método getter para as rotas do módulo
        // mas o ModuleScopedRouter é efêmero.
        // Vamos adicionar um array público de rotas registradas NESTA instância.
        $this->registeredRoutes[] = [
            'method' => $method,
            'uri' => $uri,
            'middlewares' => $middlewares,
        ];
    }

    private array $registeredRoutes = [];

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
                return Response::json(['erro' => 'Módulo desabilitado'], 404);
            }
            return $next($request);
        };

        // Coloca o guard no fim para que ele rode primeiro na pipeline (middlewares são invertidos no dispatch)
        return array_merge($middlewares, [$guard]);
    }
}
