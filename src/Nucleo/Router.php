<?php
namespace Src\Nucleo;

class Router
{
    private array $rotas = [];
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $uri, array $handler)
    {
        $this->rotas['GET'][$uri] = $handler;
    }

    public function dispatch(string $metodo, string $uri)
    {
        if (isset($this->rotas[$metodo][$uri])) {
            [$controller, $action] = $this->rotas[$metodo][$uri];
            $instancia = $this->container->make($controller);
            return $instancia->$action();
        }
        http_response_code(404);
        echo json_encode(['erro' => 'Rota não encontrada']);
    }
}
