<?php

use Dotenv\Dotenv;
use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Controllers\DashboardController;
use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Nucleo\Application;
use Src\Kernel\Nucleo\Container;
use Src\Kernel\Nucleo\ModuleLoader;
use Src\Kernel\Nucleo\PluginManager;
use Src\Kernel\Support\AuditLogger;
use Src\Kernel\Support\DB\PluginMigrator;
use Src\Kernel\Nucleo\Router;

require __DIR__ . '/vendor/autoload.php';

// Suprime headers que expõem tecnologia do servidor
header_remove('X-Powered-By');
@ini_set('expose_php', '0');

// Serve arquivos estáticos diretamente de /public
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicPath = __DIR__ . '/public' . $uri;
if ($uri !== '/' && is_file($publicPath)) {
    $ext = pathinfo($publicPath, PATHINFO_EXTENSION);
    $mime = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
    ][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($publicPath);
    exit;
}

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

function isDbConnectionError(\Throwable $e): bool
{
    $stack = [$e];
    if ($e->getPrevious() instanceof \Throwable) {
        $stack[] = $e->getPrevious();
    }

    foreach ($stack as $err) {
        if ($err instanceof \PDOException) {
            return true;
        }

        $msg = strtolower($err->getMessage());
        if (str_contains($msg, 'sqlstate[08006]') || str_contains($msg, 'connection refused') || str_contains($msg, 'não foi possível conectar ao banco')) {
            return true;
        }
    }

    return false;
}

function requestWantsJson(string $uri): bool
{
    if (strpos($uri, '/api/') === 0) {
        return true;
    }

    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && str_contains($accept, 'application/json')) {
        return true;
    }

    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    return $xrw === 'xmlhttprequest';
}

function renderDbConnectionError(string $uri): void
{
    if (requestWantsJson($uri)) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'error',
            'message' => 'Banco de dados indisponível. Tente novamente em instantes.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $driverEnv = strtolower($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql');
    $driverEnv = $driverEnv === 'postgresql' ? 'pgsql' : $driverEnv;
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $name = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
    $port = $_ENV['DB_PORT'] ?? ($driverEnv === 'pgsql' ? '5432' : '3306');
    $appEnv = $_ENV['APP_ENV'] ?? 'local';

    $templatePath = __DIR__ . '/public/db-connection-error.html';
    $html = is_file($templatePath) ? (string) file_get_contents($templatePath) : '<!doctype html><meta charset="utf-8"><title>Banco indisponível</title><h1>Banco de dados indisponível</h1>';

    $replacements = [
        '{{driver}}' => htmlspecialchars((string) $driverEnv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{host}}' => htmlspecialchars((string) $host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{port}}' => htmlspecialchars((string) $port, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{database}}' => htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{app_env}}' => htmlspecialchars((string) $appEnv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ];
    $html = strtr($html, $replacements);

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $html;
    exit;
}

if (!isset($GLOBALS['__raw_input'])) {
    $GLOBALS['__raw_input'] = file_get_contents('php://input');
}

$container = new Container();
$container->bind(ContainerInterface::class, $container, true);

try {
    $container->bind(\PDO::class, static fn() => PdoFactory::fromEnv(), true);

    $migrator = new PluginMigrator(PdoFactory::fromEnv(), __DIR__);
    $container->bind(PluginMigrator::class, $migrator, true);
} catch (\Throwable $e) {
    if (isDbConnectionError($e)) {
        renderDbConnectionError($uri);
    }
    throw $e;
}

$manager = new PluginManager($migrator, __DIR__ . '/storage');
$container->bind(PluginManager::class, $manager, true);

// Registra AuditLogger como singleton
$container->bind(AuditLogger::class, static function () use ($container) {
    try {
        return new AuditLogger($container->make(\PDO::class));
    } catch (\Throwable) {
        return new AuditLogger(null);
    }
}, true);

// Registra implementações dos contratos do Kernel.
// Estes bindings conectam o kernel aos módulos — único lugar onde isso acontece.
// O desenvolvedor de módulos não precisa tocar aqui.
$container->bind(
    \Src\Kernel\Contracts\UserRepositoryInterface::class,
    \Src\Modules\Usuario\Repositories\UsuarioRepository::class,
    true
);
$container->bind(
    \Src\Kernel\Contracts\TokenBlacklistInterface::class,
    \Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository::class,
    true
);

$router = new Router($container);
$container->bind(RouterInterface::class, $router, true);

$modules = new ModuleLoader($container);
$container->bind(ModuleLoader::class, $modules, true);

// Boot Application
$app = new Application($container, $router, $modules);
$app->boot();

// Core routes
$router->get('/', [\Src\Kernel\Controllers\HomeController::class, 'index']);
$router->get('/index.php', [\Src\Kernel\Controllers\HomeController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'index'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->get('/modules/marketplace', [\Src\Kernel\Controllers\MarketplacePageController::class, 'index'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Marketplace API (busca e instalação)
$router->get('/api/system/marketplace', [\Src\Kernel\Controllers\SystemModulesController::class, 'search'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->post('/api/system/modules/install', [\Src\Kernel\Controllers\SystemModulesController::class, 'install'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->post('/api/system/modules/uninstall', [\Src\Kernel\Controllers\SystemModulesController::class, 'uninstall'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Modules Management API (Dashboard toggles)
$router->get('/api/system/modules', [\Src\Kernel\Controllers\StatusController::class, 'modules'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->post('/api/system/modules/toggle', [\Src\Kernel\Controllers\StatusController::class, 'toggle'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Capabilities API
$router->get('/api/capabilities', [\Src\Kernel\Controllers\CapabilitiesController::class, 'index'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->post('/api/capabilities/provider', [\Src\Kernel\Controllers\CapabilitiesController::class, 'set'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

function isPrivateRoute(array $route): bool {
    $private = [
        AuthHybridMiddleware::class,
        AdminOnlyMiddleware::class,
        \Src\Kernel\Middlewares\RouteProtectionMiddleware::class,
    ];
    foreach ($route['middlewares'] ?? [] as $mw) {
        $def = $mw['definition'] ?? null;
        if (is_string($def) && in_array($def, $private, true)) {
            return true;
        }
    }
    return false;
}

function absoluteUrl(string $uri): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ('localhost');
    return rtrim($scheme . '://' . $host, '/') . $uri;
}

function isSitemapEligible(array $route): bool {
    if (strtoupper($route['method']) !== 'GET') return false;
    $uri = $route['uri'] ?? '';
    if ($uri === '') return false;
    if (strpos($uri, '/api/') === 0) return false; // não indexa APIs
    if (strpos($uri, '{') !== false) return false; // ignora rotas dinâmicas
    if (isPrivateRoute($route)) return false;
    if (in_array($uri, ['/sitemap.xml', '/robots.txt'], true)) return false;
    return true;
}

$router->get('/sitemap.xml', function () use ($router, $modules) {
    // Rotas públicas (GET, não dinâmicas, sem middlewares privados)
    $routes = array_filter($router->all(), fn(array $route) => isSitemapEligible($route));

    // Adiciona fallback das descrições dos módulos para garantir que rotas públicas habilitadas entrem no sitemap
    foreach ($modules->providers() as $name => $provider) {
        if (!$modules->isEnabled($name)) {
            continue;
        }
        $desc = $provider->describe();
        foreach (($desc['routes'] ?? []) as $route) {
            $method = strtoupper($route['method'] ?? 'GET');
            $uri = $route['uri'] ?? '';
            $isProtected = ($route['protected'] ?? false) || ($route['tipo'] ?? '') === 'privada';
            if ($method !== 'GET') continue;
            if ($uri === '' || strpos($uri, '{') !== false) continue;
            if (strpos($uri, '/api/') === 0) continue;
            if ($isProtected) continue;
            $routes[] = ['method' => 'GET', 'uri' => $uri, 'middlewares' => []];
        }
    }

    // remove duplicados e normaliza canonicals (/index.php -> /)
    $uris = [];
    foreach ($routes as $route) {
        $uri = $route['uri'];
        if ($uri === '/index.php') {
            $uri = '/';
        }
        if (in_array($uri, ['/sitemap.xml', '/robots.txt'], true)) {
            continue;
        }
        $uris[$uri] = true;
    }

    ksort($uris);

    $urls = array_map(function ($uri) {
        $loc = absoluteUrl($uri);
        return "  <url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>0.5</priority></url>";
    }, array_keys($uris));

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
        "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" .
        implode("\n", $urls) . "\n" .
        "</urlset>";

    return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
});

$router->get('/robots.txt', function () use ($router) {
    $privateUris = array_map(fn($r) => $r['uri'], array_filter($router->all(), fn($r) => isPrivateRoute($r)));
    $privateUris = array_values(array_unique(array_filter($privateUris)));

    $lines = [
        'User-agent: *',
        'Allow: /',
        'Disallow: /api/',
    ];

    foreach ($privateUris as $uri) {
        $lines[] = 'Disallow: ' . $uri;
    }

    $lines[] = 'Sitemap: ' . absoluteUrl('/sitemap.xml');

    $body = implode("\n", $lines);
    return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});

$router->get('/api/status', function () use ($modules, $router) {
    $status = [
        'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
        'port' => $_SERVER['SERVER_PORT'] ?? '80',
        'env' => $_ENV['APP_ENV'] ?? 'local',
        'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'true' : 'false',
    ];

    $moduleList = [];
    foreach ($modules->providers() as $name => $provider) {
        $enabled = $modules->isEnabled($name);
        $desc = $provider->describe();
        $routes = $enabled ? ($desc['routes'] ?? []) : [];
        // Não removemos as rotas de desc aqui, pois precisamos delas na lista de módulos
        // O front-end espera que cada módulo tenha sua lista de 'routes' para exibir na tabela "Rotas dos Módulos"

        $moduleList[] = array_merge(
            ['name' => $name, 'enabled' => $enabled],
            $desc // desc já contém 'routes'
        );
    }

    return Response::json([
        'status' => $status,
        'modules' => $moduleList,
        // O front-end usa 'modules' para exibir as rotas agrupadas por módulo
        // O campo 'routes' abaixo é redundante ou usado para outra coisa, mas vamos manter
        'routes' => array_values(array_map(
            fn($route) => [
                'method' => $route['method'],
                'uri' => $route['uri'],
            ],
            $router->all()
        )),
    ]);
});

$router->get('/api/db-status', function () use ($container) {
    $driverEnv = strtolower($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql');
    $driverEnv = $driverEnv === 'postgresql' ? 'pgsql' : $driverEnv;
    $host = $_ENV['DB_HOST'] ?? '';
    $name = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
    $port = $_ENV['DB_PORT'] ?? ($driverEnv === 'pgsql' ? '5432' : '3306');

    try {
        $pdo = $container->make(\PDO::class);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $probe = $driver === 'pgsql' ? 'SELECT 1' : 'SELECT 1';
        $pdo->query($probe);
        $dbName = null;
        try {
            $dbName = $pdo->query($driver === 'pgsql' ? 'select current_database()' : 'select database()')->fetchColumn();
        } catch (\Throwable $inner) {
            $dbName = $name;
        }

        return Response::json([
            'conectado' => true,
            'database' => [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'nome' => $dbName ?: $name,
            ],
        ]);
    } catch (\Throwable $e) {
        return Response::json([
            'conectado' => false,
            'database' => [
                'driver' => $driverEnv,
                'host' => $host,
                'port' => $port,
                'nome' => $name,
            ],
            'erro' => $e->getMessage(),
        ], 500);
    }
});

$router->get('/api/dashboard/metrics', function () use ($container, $modules, $router) {
    $status = [
        'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
        'port' => $_SERVER['SERVER_PORT'] ?? '80',
        'env' => $_ENV['APP_ENV'] ?? 'local',
        'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'true' : 'false',
    ];

    $moduleList = [];
    foreach ($modules->providers() as $name => $provider) {
        $desc = $provider->describe();
        $moduleList[] = array_merge(['name' => $name, 'enabled' => $modules->isEnabled($name)], $desc);
    }

    $dbStatus = [
        'conectado' => false,
        'database' => null,
        'erro' => null,
    ];

    try {
        $pdo = $container->make(\PDO::class);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $host = $_ENV['DB_HOST'] ?? '';
        $name = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $port = $_ENV['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306');
        $probe = $driver === 'pgsql' ? 'SELECT 1' : 'SELECT 1';
        $pdo->query($probe);
        $dbName = $pdo->query($driver === 'pgsql' ? 'select current_database()' : 'select database()')->fetchColumn();

        $dbStatus['conectado'] = true;
        $dbStatus['database'] = [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'nome' => $dbName ?: $name,
        ];
    } catch (\Throwable $e) {
        $dbStatus['erro'] = $e->getMessage();
    }

    $usuarios = ['total' => null, 'erro' => null];
    try {
        $repo = $container->make(UsuarioRepository::class);
        $usuarios['total'] = $repo->contar();
    } catch (\Throwable $e) {
        $usuarios['erro'] = $e->getMessage();
    }

    return Response::json([
        'status' => $status,
        'modules' => $moduleList,
        'routes' => array_map(
            fn($route) => [
                'method' => $route['method'],
                'uri' => $route['uri'],
            ],
            $router->all()
        ),
        'database' => $dbStatus,
        'usuarios' => $usuarios,
    ]);
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

$router->get('/api/modules/state', function () use ($modules) {
    $states = $modules->states();
    $list = [];
    foreach ($states as $name => $enabled) {
        $list[] = ['name' => $name, 'enabled' => $enabled];
    }
    return Response::json(['modules' => $list]);
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

$router->post('/api/modules/toggle', function ($request) use ($modules) {
    $body = $request->body ?? [];
    $name = $body['name'] ?? null;
    $enabled = $body['enabled'] ?? null;
    if (!$name || !is_string($name)) {
        return Response::json(['error' => 'Nome do módulo é obrigatório'], 400);
    }
    $current = $modules->isEnabled($name);
    $target = $enabled === null ? !$current : filter_var($enabled, FILTER_VALIDATE_BOOLEAN);

    if ($modules->isProtected($name) && $target === false) {
        return Response::json(['error' => "O módulo {$name} é essencial e não pode ser desabilitado."], 400);
    }

    $modules->setEnabled($name, $target);
    return Response::json(['name' => $name, 'enabled' => $modules->isEnabled($name)]);
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

$app->run();
