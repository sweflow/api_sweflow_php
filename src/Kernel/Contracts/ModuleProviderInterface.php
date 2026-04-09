<?php

namespace Src\Kernel\Contracts;

interface ModuleProviderInterface
{
    public function registerRoutes(RouterInterface $router): void;
    public function boot(ContainerInterface $container): void;
    public function describe(): array;
    public function getName(): string;
    public function setName(string $name): void;

    /**
     * Declara qual conexão de banco este módulo prefere usar.
     *
     * Valores aceitos:
     *   'core'    — usa DB_* (banco principal)
     *   'modules' — usa DB2_* (banco secundário, se configurado)
     *   'auto'    — o core decide baseado na origem do módulo
     *
     * OPCIONAL — módulos que não implementam este método recebem 'auto' por padrão.
     * O ModuleLoader usa method_exists() antes de chamar, garantindo compatibilidade
     * com módulos externos que não implementam este método.
     *
     * Não declarado na interface pois é opcional.
     * Assinatura esperada: public function preferredConnection(): string
     */

    public function onInstall(): void;
    public function onEnable(): void;
    public function onDisable(): void;
    public function onUninstall(): void;
}
