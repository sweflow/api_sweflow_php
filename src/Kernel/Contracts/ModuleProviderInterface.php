<?php

namespace Src\Kernel\Contracts;

use Src\Kernel\Http\Response\Response;

interface ModuleProviderInterface
{
    /**
     * Register module routes using only contracts.
     */
    public function registerRoutes(RouterInterface $router): void;

    /**
     * Optional boot hook for bindings.
     */
    public function boot(ContainerInterface $container): void;

    /**
     * Optional health/status info for introspection endpoints.
     */
    public function describe(): array;
}
