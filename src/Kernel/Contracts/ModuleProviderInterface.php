<?php

namespace Src\Kernel\Contracts;

interface ModuleProviderInterface
{
    public function registerRoutes(RouterInterface $router): void;
    public function boot(ContainerInterface $container): void;

    /**
     * Retorna metadados do módulo para exibição no dashboard e sitemap.
     *
     * Formato esperado:
     * [
     *     'name'        => 'NomeDoModulo',          // string — nome do módulo
     *     'description' => 'Descrição do módulo',   // string
     *     'version'     => '1.0.0',                 // string semver
     *     'routes'      => [                        // array de objetos de rota
     *         [
     *             'method'    => 'POST',            // string — GET|POST|PUT|PATCH|DELETE
     *             'uri'       => '/api/modulo/{id}',// string — URI da rota
     *             'protected' => true,              // bool   — requer autenticação?
     *             'tipo'      => 'privada',         // string — 'privada' | 'pública'
     *         ],
     *     ],
     * ]
     *
     * IMPORTANTE: o array 'routes' deve conter objetos (arrays associativos) com as
     * chaves 'method' e 'uri'. Strings no formato "POST /uri" são aceitas por
     * compatibilidade mas não são recomendadas — não permitem detectar se a rota
     * é pública ou privada.
     *
     * Se não implementar describe(), o SimpleModuleProvider detecta as rotas
     * automaticamente via registerRoutes() — recomendado para a maioria dos módulos.
     */
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
