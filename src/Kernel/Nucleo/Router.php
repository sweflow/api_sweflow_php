<?php
namespace Src\Kernel\Nucleo;

use ReflectionMethod;
use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class Router implements RouterInterface
{
    private array $routes = [];
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
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
        $normalizedMiddlewares = array_map([$this, 'normalizeMiddleware'], $middlewares);
        $this->routes[] = [
            'method' => strtoupper($method),
            'uri' => $uri,
            'pattern' => $this->compilePattern($uri),
            'handler' => $handler,
            'middlewares' => $normalizedMiddlewares,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $matched = $this->match($request->getMethod(), $request->getUri());
        if ($matched === null) {
            return Response::json(['error' => 'Rota não encontrada'], 404);
        }

        $request = $request->withParams($matched['params']);

        $runner = function (Request $req) use ($matched) {
            return $this->invokeHandler($matched['handler'], $req, $matched['params']);
        };

        $pipeline = array_reduce(
            array_reverse($matched['middlewares']),
            function ($next, array $middleware) {
                return function (Request $req) use ($middleware, $next) {
                    return $this->invokeMiddleware($middleware, $req, $next);
                };
            },
            $runner
        );

        $result = $pipeline($request);
        return $this->normalizeResponse($result);
    }

    public function all(): array
    {
        return $this->routes;
    }

    private function match(string $method, string $uri): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = $value;
                    }
                }
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middlewares' => $route['middlewares'],
                ];
            }
        }
        return null;
    }

    private function compilePattern(string $uri): string
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_-]*)\}#', '(?P<$1>[^/]+)', $uri);
        return '#^' . $pattern . '$#';
    }

    private function invokeHandler($handler, Request $request, array $params)
    {
        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $class = $handler[0];
            $method = $handler[1];
            $instance = is_string($class) ? $this->container->make($class) : $class;
            return $instance->$method($request, ...array_values($params));
        }

        if (is_callable($handler)) {
            return $handler($request, ...array_values($params));
        }

        throw new \RuntimeException('Handler inválido para a rota.');
    }

    private function invokeMiddleware(array $middleware, Request $request, callable $next)
    {
        $definition = $middleware['definition'];
        $args = $middleware['args'];

        $instance = $definition;
        if (is_string($definition)) {
            try {
                if (!empty($args) && class_exists($definition)) {
                    try {
                        $instance = new $definition(...array_values($args));
                    } catch (\Throwable) {
                        $instance = $this->container->make($definition);
                    }
                } else {
                    $instance = $this->container->make($definition);
                }
            } catch (\Throwable $e) {
                // Falha ao instanciar middleware — se for rota de página, redireciona para /
                // Se for rota de API, propaga o erro
                $uri = $request->getUri();
                $isPage = !str_starts_with($uri, '/api/');
                if ($isPage) {
                    error_log('[Router] Falha ao instanciar middleware ' . $definition . ': ' . $e->getMessage());
                    return new Response('', 302, ['Location' => '/']);
                }
                throw $e;
            }
        }

        if ($instance instanceof MiddlewareInterface) {
            return $instance->handle($request, $next);
        }

        if (is_callable($instance)) {
            return $instance($request, $next, ...$args);
        }

        if (is_object($instance) && method_exists($instance, 'handle')) {
            $reflection = new ReflectionMethod($instance, 'handle');
            return $this->callHandle($reflection, [$instance, 'handle'], $request, $next, $args);
        }

        if (is_string($definition) && method_exists($definition, 'handle')) {
            $reflection = new ReflectionMethod($definition, 'handle');
            return $this->callHandle($reflection, [$definition, 'handle'], $request, $next, $args);
        }

        return $next($request);
    }

    private function normalizeMiddleware($middleware): array
    {
        if (is_array($middleware) && isset($middleware['class'])) {
            return [
                'definition' => $middleware['class'],
                'args' => $middleware['args'] ?? [],
            ];
        }

        if (is_array($middleware) && count($middleware) === 2 && is_string($middleware[0]) && is_array($middleware[1])) {
            return [
                'definition' => $middleware[0],
                'args' => $middleware[1],
            ];
        }

        return [
            'definition' => $middleware,
            'args' => [],
        ];
    }

    private function callHandle(ReflectionMethod $method, callable $callable, Request $request, callable $next, array $args)
    {
        $params = $method->getParameters();
        $paramsCount = count($params);

        if ($paramsCount === 0) {
            $callable();
            return $next($request);
        }

        $firstParam = $params[0];
        $firstArg = $args[0] ?? null;
        $type = $firstParam->getType();
        if ($firstArg === null) {
            $firstArg = ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), Request::class, true)) ? $request : [];
        }

        if ($paramsCount === 1) {
            $response = $callable($firstArg);
            // Propaga o request original — não o $firstArg que pode ser um array de args
            return $response instanceof Response ? $response : $next($request);
        }

        $response = $callable($request, $next, ...$args);
        return $response instanceof Response ? $response : $next($request);
    }

    private function normalizeResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        // null or other primitives -> no content
        return Response::json([], 204);
    }
}
