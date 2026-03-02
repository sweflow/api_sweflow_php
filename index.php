<?php

use Dotenv\Dotenv;
use Src\Contracts\ContainerInterface;
use Src\Contracts\RouterInterface;
use Src\Database\PdoFactory;
use Src\Http\Response\Response;
use Src\Nucleo\Application;
use Src\Nucleo\Container;
use Src\Nucleo\ModuleLoader;
use Src\Nucleo\Router;

require __DIR__ . '/vendor/autoload.php';

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

if (!isset($GLOBALS['__raw_input'])) {
    $GLOBALS['__raw_input'] = file_get_contents('php://input');
}

$container = new Container();
$container->bind(ContainerInterface::class, $container, true);

// Shared PDO for modules
$container->bind(\PDO::class, static fn() => PdoFactory::fromEnv(), true);

$router = new Router($container);
$container->bind(RouterInterface::class, $router, true);

$modules = new ModuleLoader($container);
$container->bind(ModuleLoader::class, $modules, true);

// Auto-discover modules and register routes
$modules->discover(__DIR__ . '/src/Modules');
$modules->bootAll();
$modules->registerRoutes($router);

// Core routes
$router->get('/', [\Src\Controllers\HomeController::class, 'index']);
$router->get('/index.php', [\Src\Controllers\HomeController::class, 'index']);

$router->get('/api/status', function () use ($modules, $router) {
    $status = [
        'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
        'port' => $_SERVER['SERVER_PORT'] ?? '80',
        'env' => $_ENV['APP_ENV'] ?? 'local',
        'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'true' : 'false',
    ];

    $moduleList = [];
    foreach ($modules->providers() as $name => $provider) {
        $desc = $provider->describe();
        $moduleList[] = array_merge(['name' => $name], $desc);
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
    ]);
});

$router->get('/api/db-status', function () use ($container) {
    static $lastEnvMtime = null;
    static $lastResult = null;

    $envPath = __DIR__ . '/.env';
    $envMtime = file_exists($envPath) ? filemtime($envPath) : 0;

    if ($lastResult !== null && $lastEnvMtime === $envMtime) {
        return $lastResult;
    }

    try {
        $pdo = $container->make(\PDO::class);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $probe = $driver === 'pgsql' ? 'SELECT 1' : 'SELECT 1';
        $pdo->query($probe);
        $lastResult = Response::json(['conectado' => true]);
    } catch (\Throwable $e) {
        $lastResult = Response::json([
            'conectado' => false,
            'erro' => $e->getMessage(),
        ], 500);
    }

    $lastEnvMtime = $envMtime;
    return $lastResult;
});

$application = new Application($container, $router, $modules);
$application->run();
