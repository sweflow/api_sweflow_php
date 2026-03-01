<?php
namespace Src\Nucleo;

class Application
{
    private Container $container;
    private Router $router;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->router = new Router($container);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->router->dispatch($metodo, $uri);
    }
}
