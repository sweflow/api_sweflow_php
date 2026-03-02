<?php
namespace Src\Nucleo;

use Src\Contracts\ContainerInterface;
use Src\Contracts\RouterInterface;
use Src\Http\Request\RequestFactory;

class Application
{
    private ContainerInterface $container;
    private RouterInterface $router;
    private ModuleLoader $modules;

    public function __construct(ContainerInterface $container, RouterInterface $router, ModuleLoader $modules)
    {
        $this->container = $container;
        $this->router = $router;
        $this->modules = $modules;
    }

    public function boot(): void
    {
        $this->modules->bootAll();
    }

    public function router(): RouterInterface
    {
        return $this->router;
    }

    public function run(): void
    {
        $request = RequestFactory::fromGlobals();
        $response = $this->router->dispatch($request);
        $response->Enviar();
    }
}
