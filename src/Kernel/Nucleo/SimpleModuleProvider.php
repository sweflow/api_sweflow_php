<?php

namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;

/**
 * Lightweight provider created from plain config arrays so modules stay minimal.
 */
class SimpleModuleProvider implements ModuleProviderInterface
{
    private string $name;
    private string $path;
    private array $routes = [];
    private array $metadata = [];
    /** @var array<string,mixed>|null Cache do resultado de describe() */
    private ?array $describeCache = null;
    /** Módulos externos (vendor, storage/modules) passam pelo filtro de URI */
    private bool $isExternal = false;
    /** Provider delegado para boot() — ex: AccountsServiceProvider do usuário */
    private ?object $delegateBootProvider = null;

    public function __construct(string $name, string $path)
    {
        $this->name = $name;
        $this->path = $path;
        $this->isExternal = $this->detectExternal($path);
        $this->loadConfig();
    }

    /**
     * Detecta se o módulo é externo (fora de src/Modules/).
     * Módulos internos são confiáveis e não passam pelo filtro de URI.
     */
    private function detectExternal(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) return true; // se não consegue resolver, trata como externo por segurança

        // Caminho interno: .../src/Modules/NomeModulo
        $sep = DIRECTORY_SEPARATOR;
        return !str_contains($real, "{$sep}src{$sep}Modules{$sep}");
    }

    private function loadConfig(): void
    {
        $composerJson = $this->path . '/composer.json';
        if (file_exists($composerJson)) {
            $raw = file_get_contents($composerJson);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?? [];
                $this->metadata['description'] = $data['description'] ?? '';
                $this->metadata['version']     = $data['version'] ?? '1.0.0';
            }
        }

        $pluginJson = $this->path . '/plugin.json';
        if (file_exists($pluginJson)) {
            $raw = file_get_contents($pluginJson);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?? [];
                if (!empty($data['description'])) $this->metadata['description'] = $data['description'];
                if (!empty($data['version']))     $this->metadata['version']     = $data['version'];
            }
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function boot(ContainerInterface $container): void
    {
        // Se o módulo usa DB2 (connection.php = 'modules'), rebinda PDO::class
        // para que repositórios instanciados pelo container usem a conexão correta.
        $conn = $this->preferredConnection();
        if ($conn === 'modules') {
            try {
                $modulesPdo = $container->make('pdo.modules');
                $corePdo    = null;
                try { $corePdo = $container->make(\PDO::class); } catch (\Throwable) {}
                if ($modulesPdo !== $corePdo) {
                    $container->bind(\PDO::class, static fn() => $modulesPdo, true);
                }
            } catch (\Throwable) {}
        }

        // Executa o boot do provider delegado (ex: AccountsServiceProvider)
        if ($this->delegateBootProvider !== null) {
            try {
                if (method_exists($this->delegateBootProvider, 'boot')) {
                    $this->delegateBootProvider->boot($container);
                }
            } catch (\Throwable $e) {
                error_log("[SimpleModuleProvider] Erro no boot delegado de {$this->name}: " . $e->getMessage());
            }
        }
    }

    public function setDelegateBootProvider(object $provider): void
    {
        $this->delegateBootProvider = $provider;
    }

    public function registerRoutes(RouterInterface $router): void
    {
        $realBase = realpath($this->path);
        if ($realBase === false) {
            return;
        }

        $found = false;
        foreach (array_unique($this->routeCandidates()) as $f) {
            $realFile = realpath($f);
            if ($realFile !== false && str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {

                // Módulos externos: usa router proxy que filtra URIs reservadas
                // Módulos internos (src/Modules/): passa direto — são confiáveis
                if ($this->isExternal) {
                    $guardedRouter = new class($router, $this->name) implements \Src\Kernel\Contracts\RouterInterface {
                        public function __construct(
                            private readonly \Src\Kernel\Contracts\RouterInterface $inner,
                            private readonly string $moduleName
                        ) {}

                        public function get(string $uri, $handler, array $mw = []): void    { $this->add('GET',    $uri, $handler, $mw); }
                        public function post(string $uri, $handler, array $mw = []): void   { $this->add('POST',   $uri, $handler, $mw); }
                        public function put(string $uri, $handler, array $mw = []): void    { $this->add('PUT',    $uri, $handler, $mw); }
                        public function patch(string $uri, $handler, array $mw = []): void  { $this->add('PATCH',  $uri, $handler, $mw); }
                        public function delete(string $uri, $handler, array $mw = []): void { $this->add('DELETE', $uri, $handler, $mw); }

                        public function add(string $method, string $uri, $handler, array $mw = []): void
                        {
                            if (!\Src\Kernel\Nucleo\ModuleGuard::isUriAllowed($uri, $this->moduleName)) {
                                return; // URI reservada — bloqueia silenciosamente
                            }
                            $this->inner->add($method, $uri, $handler, $mw);
                        }

                        public function dispatch(\Src\Kernel\Http\Request\Request $r): \Src\Kernel\Http\Response\Response
                        {
                            return $this->inner->dispatch($r);
                        }

                        public function all(): array { return $this->inner->all(); }
                    };
                    require $realFile;
                } else {
                    // Módulo interno — sem filtro de URI
                    require $realFile;
                }

                $found = true;
            }
        }

        if ($found && $router instanceof ModuleScopedRouter) {
            $this->routes       = $router->getRegisteredRoutes();
            $this->describeCache = null;
        }
    }

    /** Retorna os caminhos candidatos de arquivos de rota deste módulo. */
    private function routeCandidates(): array
    {
        return [
            $this->path . '/Routes/web.php',
            $this->path . '/Routes/api.php',
            $this->path . '/Routes/Routes.php',
            $this->path . '/src/Routes/routes.php',
            $this->path . '/src/Routes/web.php',
            $this->path . '/src/Routes/api.php',
        ];
    }

    public function describe(): array
    {
        if ($this->describeCache !== null) {
            return $this->describeCache;
        }

        // Coleta rotas via router temporário apenas se ainda não foram populadas
        // (registerRoutes popula $this->routes durante o boot normal)
        if (empty($this->routes)) {
            $collector = new class implements RouterInterface {
                public array $collected = [];
                public function get(string $uri, $handler, array $middlewares = []): void    { $this->add('GET',    $uri, $handler, $middlewares); }
                public function post(string $uri, $handler, array $middlewares = []): void   { $this->add('POST',   $uri, $handler, $middlewares); }
                public function put(string $uri, $handler, array $middlewares = []): void    { $this->add('PUT',    $uri, $handler, $middlewares); }
                public function patch(string $uri, $handler, array $middlewares = []): void  { $this->add('PATCH',  $uri, $handler, $middlewares); }
                public function delete(string $uri, $handler, array $middlewares = []): void { $this->add('DELETE', $uri, $handler, $middlewares); }
                public function add(string $method, string $uri, $handler, array $middlewares = []): void {
                    $this->collected[] = ['method'=>$method, 'uri'=>$uri, 'handler'=>$handler, 'middlewares'=>$middlewares];
                }
                public function dispatch(\Src\Kernel\Http\Request\Request $request): \Src\Kernel\Http\Response\Response { return \Src\Kernel\Http\Response\Response::json([]); }
                public function all(): array { return $this->collected; }
            };

            foreach (array_unique($this->routeCandidates()) as $f) {
                    $realBase = realpath($this->path);
                    $realFile = realpath($f);
                    if ($realBase !== false && $realFile !== false
                        && str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
                        try {
                            $router = $collector;
                            include $realFile;
                        } catch (\Throwable) {}
                    }
                }
            $this->routes = $collector->collected;
        }
        // Middlewares que indicam autenticação obrigatória (rota privada)
        static $authMiddlewares = [
            'AuthHybridMiddleware',
            'AuthCookieMiddleware',
            'AdminOnlyMiddleware',
            'RouteProtectionMiddleware',
            'OptionalAuthHybridMiddleware',
        ];

        $this->describeCache = [
            'name'        => $this->name,
            'description' => $this->metadata['description'] ?? '',
            'version'     => $this->metadata['version'] ?? '1.0.0',
            'routes'      => array_map(function ($route) use ($authMiddlewares) {
                $isProtected = false;
                foreach ($route['middlewares'] ?? [] as $mw) {
                    $def = is_array($mw) ? ($mw[0] ?? '') : $mw;
                    if (!is_string($def) || $def === '') continue;
                    $shortName = basename(str_replace('\\', '/', $def));
                    if (in_array($shortName, $authMiddlewares, true)) {
                        $isProtected = true;
                        break;
                    }
                }

                // Enriquece com inspeção automática de campos
                $inspected = RouteInspector::inspect(
                    $route['method'] ?? 'GET',
                    $route['uri']    ?? '',
                    $route['handler'] ?? null,
                    $route['middlewares'] ?? []
                );

                return [
                    'method'      => strtoupper($route['method'] ?? 'GET'),
                    'uri'         => $route['uri'] ?? '',
                    'protected'   => $isProtected,
                    'tipo'        => $isProtected ? 'privada' : 'pública',
                    'description' => $inspected['description'],
                    'auth'        => $inspected['auth'],
                    'fields'      => $inspected['fields'],
                    'path_params' => $inspected['path_params'],
                    'query_params'=> $inspected['query_params'],
                    'body_fields' => $inspected['body_fields'],
                ];
            }, $this->routes),
        ];

        // connection é lido fora do cache — pode mudar em runtime via /api/modules/connection
        return array_merge($this->describeCache, ['connection' => $this->preferredConnection()]);
    }

    public function onInstall(): void
    {
        // Default empty implementation for simple modules
    }

    public function onEnable(): void
    {
        // Default empty implementation for simple modules
    }

    public function onDisable(): void
    {
        // Default empty implementation for simple modules
    }

    public function onUninstall(): void
    {
        // Default empty implementation for simple modules
    }

    /**
     * Lê o arquivo Database/connection.php do módulo para determinar a conexão.
     * Se não existir, retorna 'auto' (core decide baseado na origem do módulo).
     *
     * Para definir a conexão no seu módulo, crie:
     *   src/Modules/SeuModulo/Database/connection.php
     * com o conteúdo:
     *   <?php return 'core';    // usa DB_*
     *   <?php return 'modules'; // usa DB2_*
     */
    public function preferredConnection(): string
    {
        $candidates = [
            $this->path . '/Database/connection.php',
            $this->path . '/src/Database/connection.php',
            $this->path . '/database/connection.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                // Usa file_get_contents + regex em vez de include para evitar cache do OPcache
                $raw = @file_get_contents($file);
                if ($raw !== false && preg_match("/return\s+'(core|modules|auto)'\s*;/", $raw, $m)) {
                    return $m[1];
                }
            }
        }
        return 'auto';
    }
}
