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

    /**
     * Get the module name.
     */
    public function getName(): string;

    /**
     * Set the module name.
     */
    public function setName(string $name): void;

    /**
     * Declara qual conexão de banco este módulo prefere usar.
     *
     * Valores aceitos:
     *   'core'    — usa PDO::class (banco principal: Auth, Usuario, Email)
     *   'modules' — usa pdo.modules (banco secundário DB2_*, se configurado)
     *   'auto'    — o core decide: módulos nativos usam 'core', externos usam 'modules'
     *
     * O core sempre valida e controla — o módulo apenas declara preferência.
     * Se 'modules' for declarado mas DB2_* não estiver configurado, o core
     * usa automaticamente a conexão principal sem erro.
     */
    public function preferredConnection(): string;

    public function onInstall(): void;
    public function onEnable(): void;
    public function onDisable(): void;
    public function onUninstall(): void;
}
