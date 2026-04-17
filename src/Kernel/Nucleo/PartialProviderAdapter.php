<?php

declare(strict_types=1);

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;

/**
 * Adapter que envolve um provider parcial (sem todos os métodos da interface)
 * e implementa os métodos faltantes com stubs vazios.
 *
 * Permite que módulos declarem apenas boot() e registerRoutes() sem precisar
 * implementar setName, onInstall, onEnable, onDisable, onUninstall.
 */
final class PartialProviderAdapter implements ModuleProviderInterface
{
    private string $name;
    private string $path;
    private object $delegate;

    public function __construct(string $name, string $path, object $delegate)
    {
        $this->name     = $name;
        $this->path     = $path;
        $this->delegate = $delegate;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function boot(ContainerInterface $container): void
    {
        if (method_exists($this->delegate, 'boot')) {
            $this->delegate->boot($container);
        }
    }

    public function registerRoutes(RouterInterface $router): void
    {
        if (method_exists($this->delegate, 'registerRoutes')) {
            $this->delegate->registerRoutes($router);
        }
    }

    public function describe(): array
    {
        if (method_exists($this->delegate, 'describe')) {
            return (array) $this->delegate->describe();
        }
        return ['description' => '', 'version' => '1.0.0', 'routes' => []];
    }

    public function onInstall(): void
    {
        if (method_exists($this->delegate, 'onInstall')) {
            $this->delegate->onInstall();
        }
    }

    public function onEnable(): void
    {
        if (method_exists($this->delegate, 'onEnable')) {
            $this->delegate->onEnable();
        }
    }

    public function onDisable(): void
    {
        if (method_exists($this->delegate, 'onDisable')) {
            $this->delegate->onDisable();
        }
    }

    public function onUninstall(): void
    {
        if (method_exists($this->delegate, 'onUninstall')) {
            $this->delegate->onUninstall();
        }
    }

    public function preferredConnection(): string
    {
        if (method_exists($this->delegate, 'preferredConnection')) {
            return (string) $this->delegate->preferredConnection();
        }
        // Lê connection.php do módulo
        $connFile = $this->path . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'connection.php';
        if (is_file($connFile)) {
            try {
                $val = (string)(include $connFile);
                if (in_array($val, ['core', 'modules', 'auto'], true)) {
                    return $val;
                }
            } catch (\Throwable) {}
        }
        return 'core';
    }

    public function getPath(): string { return $this->path; }
}
