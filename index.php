<?php

// Inicia output buffering imediatamente para capturar qualquer output espúrio
// (warnings de extensões, notices, etc.) antes de enviar headers/body JSON.
ob_start();

use Dotenv\Dotenv;
use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Controllers\DashboardController;
use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AuthPageMiddleware;
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
ini_set('expose_php', '0');

// ── Carrega .env antes de qualquer validação ──────────────────────────────
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// ── Preflight CORS (OPTIONS) ──────────────────────────────────────────────
// Deve rodar antes de qualquer lógica — o browser envia OPTIONS antes do POST/PUT real.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = array_filter(array_map('trim', explode(',',
        ($_ENV['CORS_ALLOWED_ORIGINS'] ?? '') . ',' .
        ($_ENV['APP_URL_FRONTEND']     ?? '') . ',' .
        ($_ENV['APP_URL']              ?? '')
    )));
    if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Device-Id, X-Client-Public-IP');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(204);
    exit;
}

// ── Validação de segredos críticos em produção ────────────────────────────
(static function (): void {
    $isProduction = ($_ENV['APP_ENV'] ?? 'local') === 'production';
    if (!$isProduction) {
        return;
    }

    $weakPatterns = [
        '/^eyJ/', // JWT completo usado como secret (não é um segredo, é um token)
        '/^(secret|password|changeme|12345|admin|test|example)/i',
    ];

    $secrets = [
        'JWT_SECRET'     => $_ENV['JWT_SECRET']     ?? '',
        'JWT_API_SECRET' => $_ENV['JWT_API_SECRET']  ?? '',
    ];

    foreach ($secrets as $name => $value) {
        if (strlen($value) < 32) {
            if (ob_get_level() > 0) ob_end_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => "Configuração inválida: $name deve ter ao menos 32 caracteres."]);
            exit(1);
        }
        foreach ($weakPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                if (ob_get_level() > 0) ob_end_clean();
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => "Configuração inválida: $name contém um valor inseguro."]);
                exit(1);
            }
        }
    }

    $dbPass = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
    $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
    $isLocalDb = in_array($dbHost, ['localhost', '127.0.0.1', '::1'], true);
    if (!$isLocalDb && strlen($dbPass) < 16) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Configuração inválida: DB_SENHA deve ter ao menos 16 caracteres em produção com banco remoto.']);
        exit(1);
    }
})();

// Serve arquivos estáticos diretamente de /public
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($uri !== '/') {
    $baseReal   = realpath(__DIR__ . '/public');
    $targetReal = $baseReal !== false ? realpath($baseReal . $uri) : false;

    // ── Path traversal: garante containment dentro de /public ────────────
    $dentroDoPublic = $baseReal !== false
        && $targetReal !== false
        && str_starts_with($targetReal, $baseReal . DIRECTORY_SEPARATOR)
        && is_file($targetReal);

    if ($dentroDoPublic) {
        $ext = strtolower(pathinfo($targetReal, PATHINFO_EXTENSION));

        // ── Whitelist de extensões permitidas ────────────────────────────
        $mimeMap = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'ttf'   => 'font/ttf',
            'webp'  => 'image/webp',
            'html'  => 'text/html; charset=utf-8',
        ];

        if (!array_key_exists($ext, $mimeMap)) {
            http_response_code(403);
            exit;
        }

        // ── Bloqueia extensões sensíveis mesmo que passem pelo realpath ──
        if (preg_match('/\.(env|log|ini|sql|bak|sh|php|phtml|phar|key|pem|crt|cfg|conf|json|lock|xml|yaml|yml)$/i', $targetReal)) {
            http_response_code(403);
            exit;
        }

        $isHttps = \Src\Kernel\Support\CookieConfig::isHttps();        header_remove('X-Powered-By');
        header('Content-Type: ' . $mimeMap[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        if (ob_get_level() > 0) ob_end_clean();
        readfile($targetReal);
        exit;
    }
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
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
        }
        if (ob_get_level() > 0) ob_end_clean();
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
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; script-src \'self\'; img-src \'self\' data:; frame-ancestors \'none\'');
    }
    if (ob_get_level() > 0) ob_end_clean();
    echo $html;
    exit;
}

if (!isset($GLOBALS['__raw_input'])) {
    $GLOBALS['__raw_input'] = file_get_contents('php://input');
}

// ── /api/db-status — responde antes de tentar conectar ao banco ───────────
// Permite que a página de erro verifique se o banco voltou sem depender do boot completo.
if ($uri === '/api/db-status' || str_starts_with($uri, '/api/db-status?')) {
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = array_filter(array_map('trim', explode(',',
        ($_ENV['CORS_ALLOWED_ORIGINS'] ?? '') . ',' .
        ($_ENV['APP_URL_FRONTEND']     ?? '') . ',' .
        ($_ENV['APP_URL']              ?? '')
    )));
    if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $testPdo = PdoFactory::fromEnv();
        $testPdo->query('SELECT 1');
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(200);
        echo json_encode(['conectado' => true]);
    } catch (\Throwable) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(503);
        echo json_encode(['conectado' => false]);
    }
    exit;
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
        $pdo = $container->make(\PDO::class);
        // Auto-cria a tabela audit_logs se não existir (evita erro em produção sem migration)
        try {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                    id BIGSERIAL PRIMARY KEY,
                    evento VARCHAR(100) NOT NULL,
                    usuario_uuid UUID NULL,
                    contexto JSONB NOT NULL DEFAULT '{}',
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    user_agent VARCHAR(512) NOT NULL DEFAULT '',
                    endpoint VARCHAR(255) NOT NULL DEFAULT '',
                    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    evento VARCHAR(100) NOT NULL,
                    usuario_uuid CHAR(36) NULL,
                    contexto JSON NOT NULL,
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    user_agent VARCHAR(512) NOT NULL DEFAULT '',
                    endpoint VARCHAR(255) NOT NULL DEFAULT '',
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Throwable) {}
        $ctx = $container->make(\Src\Kernel\Support\RequestContext::class);
        return new AuditLogger($pdo, $ctx);
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

// Registra MailerService se MAILER_HOST estiver configurado
$container->bind(
    \Src\Kernel\Contracts\EmailSenderInterface::class,
    static function () {
        $host = trim((string) ($_ENV['MAILER_HOST'] ?? ''));
        $user = trim((string) ($_ENV['MAILER_USERNAME'] ?? ''));
        if ($host === '' || $user === '') {
            return null;
        }
        return new \Src\Kernel\Support\MailerService();
    },
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
    AuthPageMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->get('/dashboard/usuarios', [\Src\Kernel\Controllers\UsuariosPageController::class, 'index'], [
    AuthPageMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->get('/modules/marketplace', [\Src\Kernel\Controllers\MarketplacePageController::class, 'index'], [
    AuthPageMiddleware::class,
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

// Verificação de disponibilidade de username/email em tempo real
$router->get('/api/usuarios/check-username', function ($request) use ($container) {
    $username = trim(strtolower((string) ($request->query['username'] ?? '')));
    $excludeUuid = trim((string) ($request->query['exclude'] ?? ''));
    if ($username === '') {
        return Response::json(['available' => false, 'error' => 'Username obrigatório.'], 422);
    }
    try {
        $repo = $container->make(\Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface::class);
        $exists = $repo->usernameExiste($username, $excludeUuid ?: null);
        return Response::json(['available' => !$exists]);
    } catch (\Throwable $e) {
        return Response::json(['available' => false, 'error' => $e->getMessage()], 500);
    }
}, [\Src\Kernel\Middlewares\AuthHybridMiddleware::class, \Src\Kernel\Middlewares\AdminOnlyMiddleware::class]);

$router->get('/api/usuarios/check-email', function ($request) use ($container) {
    $email = trim((string) ($request->query['email'] ?? ''));
    $excludeUuid = trim((string) ($request->query['exclude'] ?? ''));
    if ($email === '') {
        return Response::json(['available' => false, 'error' => 'E-mail obrigatório.'], 422);
    }
    try {
        $repo = $container->make(\Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface::class);
        $exists = $repo->emailExiste($email, $excludeUuid ?: null);
        return Response::json(['available' => !$exists]);
    } catch (\Throwable $e) {
        return Response::json(['available' => false, 'error' => $e->getMessage()], 500);
    }
}, [\Src\Kernel\Middlewares\AuthHybridMiddleware::class, \Src\Kernel\Middlewares\AdminOnlyMiddleware::class]);

function isPrivateRoute(array $route): bool {
    $private = [
        AuthHybridMiddleware::class,
        AdminOnlyMiddleware::class,
        \Src\Kernel\Middlewares\RouteProtectionMiddleware::class,
        \Src\Kernel\Middlewares\AuthPageMiddleware::class,
        \Src\Kernel\Middlewares\AuthCookieMiddleware::class,
        \Src\Kernel\Middlewares\ApiTokenMiddleware::class,
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
    // Usa APP_URL como fonte autoritativa quando disponível
    $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
    if ($appUrl !== '') {
        return $appUrl . $uri;
    }
    // Fallback: detecta esquema via CookieConfig (respeita TRUST_PROXY e X-Forwarded-Proto)
    $scheme = \Src\Kernel\Support\CookieConfig::isHttps() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $uri;
}

/** URIs de honeypot — registradas no router mas nunca devem aparecer no sitemap. */
function honeypotUris(): array {
    return [
        '/wp-admin', '/wp-login.php', '/wp-config.php', '/xmlrpc.php',
        '/phpmyadmin', '/pma', '/admin', '/administrator',
        '/shell', '/cmd', '/exec', '/eval',
        '/.env', '/.git/config', '/config.php', '/backup',
        '/cgi-bin/luci', '/boaform/admin/formLogin',
    ];
}

function isSitemapEligible(array $route): bool {
    if (strtoupper($route['method']) !== 'GET') return false;
    $uri = $route['uri'] ?? '';
    if ($uri === '') return false;
    if (strpos($uri, '/api/') === 0) return false;       // não indexa APIs
    if (strpos($uri, '{') !== false) return false;        // ignora rotas dinâmicas
    if (isPrivateRoute($route)) return false;             // rotas autenticadas
    if (in_array($uri, honeypotUris(), true)) return false; // honeypots
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
        // Honeypot — bots que ignoram robots.txt e acessam estas rotas são banidos
        'Disallow: /wp-admin/',
        'Disallow: /wp-login.php',
        'Disallow: /phpmyadmin/',
        'Disallow: /admin/',
        'Disallow: /.env',
        'Disallow: /config/',
        'Disallow: /backup/',
        'Disallow: /shell',
        'Disallow: /xmlrpc.php',
    ];

    foreach ($privateUris as $uri) {
        $lines[] = 'Disallow: ' . $uri;
    }

    $lines[] = 'Sitemap: ' . absoluteUrl('/sitemap.xml');

    $body = implode("\n", $lines);
    return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});

// ── Honeypot — rotas que bots costumam escanear ───────────────────────────
// Qualquer acesso é registrado para Fail2Ban. Retorna 404 para não revelar que é honeypot.
(static function () use ($router): void {
    $honeypotPaths = honeypotUris();
    $honeypotHandler = function (): Response {
        $ip  = \Src\Kernel\Support\IpResolver::resolve();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
        $env = $_ENV['APP_ENV'] ?? 'production';

        (new \Src\Kernel\Support\ThreatScorer())->add($ip, \Src\Kernel\Support\ThreatScorer::SCORE_HONEYPOT);

        if ($env !== 'testing') {
            $line = json_encode([
                'timestamp'  => date('Y-m-d\TH:i:sP'),
                'type'       => 'BOT_HONEYPOT',
                'event'      => 'honeypot.hit',
                'ip'         => $ip,
                'uri'        => $uri,
                'user_agent' => $ua,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
        }
        return Response::json(['error' => 'Not Found'], 404);
    };
    foreach ($honeypotPaths as $path) {
        $router->get($path,  $honeypotHandler);
        $router->post($path, $honeypotHandler);
    }
})();

$router->get('/api/status', function () {
    return Response::json(['status' => 'ok']);
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

$router->get('/api/db-status', function () use ($container) {
    // Rota pública — retorna apenas conectado/desconectado, sem detalhes de infra
    try {
        $pdo = $container->make(\PDO::class);
        $pdo->query('SELECT 1');
        return Response::json(['conectado' => true]);
    } catch (\Throwable $e) {
        return Response::json(['conectado' => false], 503);
    }
}, [[RateLimitMiddleware::class, ['limit' => 10, 'window' => 60, 'key' => 'api.dbstatus']]]);

$router->get('/api/db-status/details', function () use ($container) {
    // Rota protegida — retorna driver para admins
    try {
        $pdo = $container->make(\PDO::class);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $pdo->query('SELECT 1');
        return Response::json(['conectado' => true, 'database' => ['driver' => $driver]]);
    } catch (\Throwable $e) {
        return Response::json(['conectado' => false], 503);
    }
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

$router->get('/api/dashboard/metrics', function () use ($container, $modules) {
    $status = [
        'host' => $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'),
        'env' => $_ENV['APP_ENV'] ?? 'local',
        'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'true' : 'false',
    ];

    $moduleList = [];
    foreach ($modules->providers() as $name => $provider) {
        $desc = $provider->describe();
        // Rota protegida por admin — inclui routes para o dashboard exibir
        $moduleList[] = array_merge(['name' => $name, 'enabled' => $modules->isEnabled($name)], $desc);
    }

    $dbStatus = [
        'conectado' => false,
        'database' => null,
    ];

    try {
        $pdo = $container->make(\PDO::class);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $pdo->query('SELECT 1');

        $dbStatus['conectado'] = true;
        $dbStatus['database'] = ['driver' => $driver];
    } catch (\Throwable $e) {
        $dbStatus['conectado'] = false;
    }

    $usuarios = ['total' => null];
    try {
        $repo = $container->make(\Src\Modules\Usuario\Repositories\UsuarioRepository::class);
        $usuarios['total'] = $repo->contar();
    } catch (\Throwable $e) {
        // silently fail
    }

    return Response::json([
        'status' => $status,
        'modules' => $moduleList,
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
