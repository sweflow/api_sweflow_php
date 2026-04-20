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
use Src\Kernel\Nucleo\Router;
use Src\Kernel\Support\AuditLogger;
use Src\Kernel\Support\DB\PluginMigrator;

require __DIR__ . '/vendor/autoload.php';

// Suprime headers que expõem tecnologia do servidor
header_remove('X-Powered-By');
ini_set('expose_php', '0');

// Aumenta o limite de memória para 256MB (padrão é 128MB)
// Previne erros em ambientes com muitos módulos ou operações pesadas
ini_set('memory_limit', '256M');

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

    // APP_DEBUG nunca deve ser true em produção — força false silenciosamente
    if (in_array(strtolower(trim($_ENV['APP_DEBUG'] ?? 'false')), ['1', 'true', 'on', 'yes'], true)) {
        $_ENV['APP_DEBUG'] = 'false';
        putenv('APP_DEBUG=false');
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

        $isHttps = \Src\Kernel\Support\CookieConfig::isHttps();
        header_remove('X-Powered-By');
        header('Content-Type: ' . $mimeMap[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
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

function renderDbConnectionError(string $uri, string $reason = ''): void
{
    if (\Src\Kernel\Exceptions\Handler::requestWantsJson($uri)) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
        }
        if (ob_get_level() > 0) ob_end_clean();
        $msg = $reason === 'timeout'
            ? 'Banco de dados não respondeu dentro do tempo limite. Verifique host/porta no .env.'
            : 'Banco de dados indisponível. Tente novamente em instantes.';
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
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

    // Injeta ?reason=timeout na URL do botão "Tentar novamente" para o JS exibir o badge correto
    if ($reason === 'timeout') {
        $html = str_replace(
            "var params = new URLSearchParams(window.location.search);",
            "var params = new URLSearchParams('reason=timeout');",
            $html
        );
    }

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

// ── Captura Fatal Errors (ex: max_execution_time) e exibe página amigável ─
// register_shutdown_function é o único jeito de interceptar erros fatais no PHP.
register_shutdown_function(static function () use ($uri): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    // E_ERROR cobre: fatal errors, max_execution_time, memory exhausted, etc.
    if (($error['type'] & E_ERROR) === 0) {
        return;
    }

    $isTimeout = str_contains($error['message'], 'Maximum execution time')
        || str_contains($error['message'], 'maximum execution time');

    $isDbRelated = str_contains($error['file'] ?? '', 'PdoFactory')
        || str_contains($error['file'] ?? '', 'Conexao')
        || str_contains($error['message'], 'PDO')
        || str_contains($error['message'], 'database');

    // Timeout em arquivo de banco = banco travou a conexão
    if ($isTimeout && $isDbRelated) {
        if (ob_get_level() > 0) ob_end_clean();
        renderDbConnectionError($uri, 'timeout');
        return;
    }

    // Outros fatais em produção: página genérica sem vazar detalhes
    $isProduction = ($_ENV['APP_ENV'] ?? 'local') === 'production';
    if ($isProduction && !headers_sent()) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Erro interno</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
            . '<h1>500 — Erro interno</h1><p>Algo deu errado. Tente novamente em instantes.</p>'
            . '</body></html>';
    }
});

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
    // Cache de PDO por request (evita múltiplas conexões na mesma requisição)
    $pdoCache = null;
    $pdoCacheKey = null;
    
    // PDO principal - resolve automaticamente a conexão personalizada do desenvolvedor
    // APENAS para módulos não-nativos (módulos desenvolvidos pelo usuário)
    $container->bind(\PDO::class, static function() use (&$pdoCache, &$pdoCacheKey) {
        // Lista de módulos nativos que SEMPRE usam banco core
        $nativeModules = ['auth', 'authenticador', 'usuario', 'documentacao', 'idemodebuilder', 'idemodulebuilder', 'system'];
        
        // Detecta qual módulo está sendo acessado pela URI
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $isNativeModule = false;
        
        // Verifica se a URI corresponde a um módulo nativo
        foreach ($nativeModules as $native) {
            if (stripos($requestUri, "/api/{$native}") !== false || 
                stripos($requestUri, "/{$native}/") !== false) {
                $isNativeModule = true;
                break;
            }
        }
        
        // Cria chave de cache baseada no contexto
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $cacheKey = $isNativeModule ? 'core' : ($authHeader ? md5($authHeader) : 'core');
        
        // Retorna do cache se já foi resolvido nesta requisição
        if ($pdoCache !== null && $pdoCacheKey === $cacheKey) {
            return $pdoCache;
        }
        
        // Módulos nativos sempre usam banco core
        if ($isNativeModule) {
            $pdoCacheKey = $cacheKey;
            return $pdoCache = PdoFactory::fromEnv('DB');
        }
        
        // Para módulos do desenvolvedor, tenta obter a conexão personalizada
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode($parts[1]), true);
                $userId = $payload['sub'] ?? null;
                
                if ($userId) {
                    try {
                        // Busca a conexão personalizada ativa (query otimizada com índice)
                        $corePdo = PdoFactory::fromEnv('DB');
                        $stmt = $corePdo->prepare("
                            SELECT host, port, database_name, username, password, driver 
                            FROM ide_database_connections 
                            WHERE usuario_uuid = ? AND is_active = true 
                            LIMIT 1
                        ");
                        $stmt->execute([$userId]);
                        $conn = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($conn) {
                            // Descriptografa a senha
                            $key = $_ENV['APP_KEY'] ?? $_ENV['JWT_SECRET'] ?? 'default-key';
                            $data = base64_decode($conn['password']);
                            $iv = substr($data, 0, 16);
                            $encrypted = substr($data, 16);
                            $password = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
                            
                            if ($password === false) {
                                throw new \RuntimeException("Falha ao descriptografar senha do banco de dados");
                            }
                            
                            // Cria o PDO com a conexão personalizada
                            $driver = $conn['driver'] ?? 'pgsql';
                            
                            // Para PostgreSQL com SSL (Aiven), adiciona sslmode no DSN
                            if ($driver === 'pgsql') {
                                $dsn = "{$driver}:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']};sslmode=require";
                            } else {
                                $dsn = "{$driver}:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']}";
                            }
                            
                            $options = [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                PDO::ATTR_PERSISTENT => true, // ← PERSISTENT CONNECTION (reutiliza TCP)
                                PDO::ATTR_EMULATE_PREPARES => false,
                                PDO::ATTR_TIMEOUT => 5, // Timeout de 5 segundos
                            ];
                            
                            $pdoCacheKey = $cacheKey;
                            return $pdoCache = new PDO($dsn, $conn['username'], $password, $options);
                        }
                    } catch (\Throwable $e) {
                        error_log("[PDO Resolver] Erro: " . $e->getMessage());
                    }
                }
            }
        }
        
        // Fallback: usa a conexão padrão
        $pdoCacheKey = $cacheKey;
        return $pdoCache = PdoFactory::fromEnv('DB');
    }, false); // NÃO é singleton - mas usa cache manual por request

    // Segunda conexão para módulos externos (DB2_*).
    // Se DB2_NOME não estiver definido, módulos externos usam a mesma conexão do core.
    $container->bind('pdo.modules', static fn() => PdoFactory::hasSecondaryConnection()
        ? PdoFactory::fromEnv('DB2')
        : PdoFactory::fromEnv('DB'),
    true);

    $migrator = new PluginMigrator(PdoFactory::fromEnv('DB'), __DIR__);
    $container->bind(PluginMigrator::class, $migrator, true);
} catch (\Throwable $e) {
    if (\Src\Kernel\Exceptions\Handler::isDbConnectionError($e)) {
        renderDbConnectionError($uri);
    }
    throw $e;
}

$manager = new PluginManager($migrator, __DIR__ . '/storage');
$container->bind(PluginManager::class, $manager, true);

// Registra RateLimitStorage — Redis se disponível, File como fallback
$container->bind(
    \Src\Kernel\Contracts\RateLimitStorageInterface::class,
    static fn() => \Src\Kernel\Support\Storage\RateLimitStorageFactory::create(),
    true
);

// Registra ThreatScorer como singleton (evita I/O redundante por request)
$container->bind(
    \Src\Kernel\Support\ThreatScorer::class,
    static function () use ($container) {
        return new \Src\Kernel\Support\ThreatScorer(
            $container->make(\Src\Kernel\Contracts\RateLimitStorageInterface::class)
        );
    },
    true
);

// Registra AuditLogger como singleton
// A tabela audit_logs é verificada uma única vez por processo (flag estático),
// evitando DDL desnecessário a cada request em produção.
$container->bind(AuditLogger::class, static function () use ($container) {
    try {
        $pdo = $container->make(\PDO::class);
        // Garante que a tabela existe — executado apenas uma vez por processo PHP-FPM worker
        static $tableEnsured = false;
        if (!$tableEnsured) {
            $tableEnsured = true;
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
        }
        $ctx = $container->make(\Src\Kernel\Support\RequestContext::class);
        return new AuditLogger($pdo, $ctx);
    } catch (\Throwable) {
        return new AuditLogger(null);
    }
}, true);

// Registra implementações dos contratos do Kernel.
// Todos os bindings são condicionais — o sistema funciona mesmo sem os módulos nativos.
// Se os módulos Auth, Usuario ou IdeModuleBuilder não existirem, o servidor continua
// funcionando sem autenticação, gerenciamento de usuários ou IDE.
//
// IMPORTANTE: UserRepositoryInterface, TokenBlacklistInterface e AuthContextInterface
// são registrados APÓS o app->boot() (mais abaixo), para que módulos externos possam
// sobrescrevê-los no boot() do seu provider antes do fallback nativo entrar.

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

// Registra AuthController com todas as dependências injetadas via container
if (class_exists(\Src\Modules\Auth\Controllers\AuthController::class)) {
    $container->bind(
        \Src\Modules\Auth\Controllers\AuthController::class,
        static function () use ($container) {
            $pdo             = \Src\Kernel\Database\ModuleConnectionResolver::forModule('Auth');
            $userRepo        = $container->make(\Src\Kernel\Contracts\UserRepositoryInterface::class);
            $refreshRepo     = new \Src\Modules\Auth\Repositories\RefreshTokenRepository($pdo);
            $blacklist        = new \Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository($pdo);
            $authService     = new \Src\Modules\Auth\Services\AuthService($userRepo, $refreshRepo);
            $auditLogger     = $container->make(\Src\Kernel\Support\AuditLogger::class);
            $threatScorer    = $container->make(\Src\Kernel\Support\ThreatScorer::class);
            $emailService    = $container->make(\Src\Kernel\Contracts\EmailSenderInterface::class);
            $requestContext  = $container->make(\Src\Kernel\Support\RequestContext::class);
            return new \Src\Modules\Auth\Controllers\AuthController(
                $authService,
                $refreshRepo,
                $blacklist,
                $auditLogger,
                $threatScorer,
                $emailService,
                $requestContext,
            );
        },
        true
    );
}

// Registra UsuarioController com EmailThrottle injetado
if (class_exists(\Src\Modules\Usuario\Controllers\UsuarioController::class)) {
    $container->bind(
        \Src\Modules\Usuario\Controllers\UsuarioController::class,
        static function () use ($container) {
            $pdo         = \Src\Kernel\Database\ModuleConnectionResolver::forModule('Usuario');
            $service     = $container->make(\Src\Modules\Usuario\Services\UsuarioServiceInterface::class);
            $emailSender = $container->make(\Src\Kernel\Contracts\EmailSenderInterface::class);
            $emailThrottle = new \Src\Kernel\Support\EmailThrottle($pdo);
            return new \Src\Modules\Usuario\Controllers\UsuarioController($service, $emailThrottle, $emailSender);
        },
        true
    );
}

// Registra IdeProjectController com PDO injetado
if (class_exists(\Src\Modules\IdeModuleBuilder\Controllers\IdeProjectController::class)) {
    $container->bind(
        \Src\Modules\IdeModuleBuilder\Controllers\IdeProjectController::class,
        static function () use ($container) {
            $pdo        = $container->make(\PDO::class);
            $pdoModules = null;
            try { $pdoModules = $container->make('pdo.modules'); } catch (\Throwable) {}
            $service = new \Src\Modules\IdeModuleBuilder\Services\IdeProjectService($pdo, $container->make(\Src\Kernel\Nucleo\ModuleLoader::class));
            return new \Src\Modules\IdeModuleBuilder\Controllers\IdeProjectController($service, $pdo, $pdoModules);
        },
        true
    );
}

// Registra DatabaseConnectionController com PDO injetado
if (class_exists(\Src\Modules\IdeModuleBuilder\Controllers\DatabaseConnectionController::class)) {
    $container->bind(
        \Src\Modules\IdeModuleBuilder\Controllers\DatabaseConnectionController::class,
        static function () use ($container) {
            $pdo = $container->make(\PDO::class);
            $repository = new \Src\Modules\IdeModuleBuilder\Repositories\DatabaseConnectionRepository($pdo);
            $service = new \Src\Modules\IdeModuleBuilder\Services\DatabaseConnectionService();
            return new \Src\Modules\IdeModuleBuilder\Controllers\DatabaseConnectionController($repository, $service);
        },
        true
    );
}

// Registra DatabaseStatusController com PDO injetado
if (class_exists(\Src\Modules\IdeModuleBuilder\Controllers\DatabaseStatusController::class)) {
    $container->bind(
        \Src\Modules\IdeModuleBuilder\Controllers\DatabaseStatusController::class,
        static function () use ($container) {
            $pdo = $container->make(\PDO::class);
            $pdoModules = null;
            
            // Tenta obter PDO secundário (DB2) se configurado
            try {
                if (\Src\Kernel\Database\PdoFactory::hasSecondaryConnection()) {
                    $pdoModules = \Src\Kernel\Database\PdoFactory::fromEnv('DB2');
                }
            } catch (\Throwable $e) {
                // Silencioso - DB2 é opcional
            }
            
            $repository = new \Src\Modules\IdeModuleBuilder\Repositories\DatabaseConnectionRepository($pdo);
            $service = new \Src\Modules\IdeModuleBuilder\Services\DatabaseConnectionService();
            $projectService = $container->make(\Src\Modules\IdeModuleBuilder\Services\IdeProjectService::class);
            
            return new \Src\Modules\IdeModuleBuilder\Controllers\DatabaseStatusController(
                $repository,
                $service,
                $projectService,
                $pdo,
                $pdoModules
            );
        },
        true
    );
}

$router = new Router($container);
$container->bind(RouterInterface::class, $router, true);

$modules = new ModuleLoader($container);
$container->bind(ModuleLoader::class, $modules, true);

// Boot Application
$app = new Application($container, $router, $modules);
$app->boot();

// ── Fallbacks de Auth — registrados APÓS o boot dos módulos ──────────────
// Módulos externos podem sobrescrever UserRepositoryInterface,
// TokenBlacklistInterface e AuthContextInterface no boot() do seu provider.
// Só registramos o nativo se nenhum módulo externo já o fez.

// Módulo Usuario — UserRepository (fallback nativo)
if (!$container->hasBinding(\Src\Kernel\Contracts\UserRepositoryInterface::class)) {
    if (class_exists(\Src\Modules\Usuario\Repositories\UsuarioRepository::class)) {
        $container->bind(
            \Src\Kernel\Contracts\UserRepositoryInterface::class,
            static fn() => new \Src\Modules\Usuario\Repositories\UsuarioRepository(
                \Src\Kernel\Database\ModuleConnectionResolver::forModule('Usuario')
            ),
            true
        );
    }
}

// UsuarioRepositoryInterface — sempre nativo (interface interna do módulo Usuario)
if (class_exists(\Src\Modules\Usuario\Repositories\UsuarioRepository::class)
    && !$container->hasBinding(\Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface::class)) {
    $container->bind(
        \Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface::class,
        static fn() => new \Src\Modules\Usuario\Repositories\UsuarioRepository(
            \Src\Kernel\Database\ModuleConnectionResolver::forModule('Usuario')
        ),
        true
    );
}

// Módulo Auth — TokenBlacklist (fallback nativo ou null)
if (!$container->hasBinding(\Src\Kernel\Contracts\TokenBlacklistInterface::class)) {
    if (class_exists(\Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository::class)) {
        $container->bind(
            \Src\Kernel\Contracts\TokenBlacklistInterface::class,
            static function() {
                // AccessTokenBlacklistRepository SEMPRE usa banco core
                // (tabela revoked_access_tokens está no banco do sistema)
                return new \Src\Modules\Auth\Repositories\AccessTokenBlacklistRepository(
                    PdoFactory::fromEnv('DB')
                );
            },
            true
        );
    } else {
        // Blacklist nula — tokens nunca são revogados (sem módulo Auth)
        $container->bind(
            \Src\Kernel\Contracts\TokenBlacklistInterface::class,
            static fn() => new class implements \Src\Kernel\Contracts\TokenBlacklistInterface {
                public function isRevoked(string $jti): bool { return false; }
                public function revoke(string $jti, string $userUuid, \DateTimeImmutable $expiresAt): void {}
                public function purgeExpired(): void {}
            },
            true
        );
    }
}

// ── Auth pipeline — registrado APÓS o boot dos módulos ───────────────────
// Cada contrato pode ser substituído independentemente por módulos externos.
// A ordem importa: os colaboradores são registrados antes do orquestrador.

// TokenResolverInterface — de onde vem o token?
if (!$container->hasBinding(\Src\Kernel\Contracts\TokenResolverInterface::class)) {
    $container->bind(
        \Src\Kernel\Contracts\TokenResolverInterface::class,
        \Src\Kernel\Auth\BearerTokenResolver::class,
        true
    );
}

// TokenValidatorInterface — o token é válido?
if (!$container->hasBinding(\Src\Kernel\Contracts\TokenValidatorInterface::class)) {
    if ($container->hasBinding(\Src\Kernel\Contracts\TokenBlacklistInterface::class)) {
        $container->bind(
            \Src\Kernel\Contracts\TokenValidatorInterface::class,
            static function () use ($container) {
                return new \Src\Kernel\Auth\JwtTokenValidator(
                    $container->make(\Src\Kernel\Contracts\TokenBlacklistInterface::class)
                );
            },
            true
        );
    }
}

// UserResolverInterface — quem é o usuário?
if (!$container->hasBinding(\Src\Kernel\Contracts\UserResolverInterface::class)) {
    if ($container->hasBinding(\Src\Kernel\Contracts\UserRepositoryInterface::class)) {
        $container->bind(
            \Src\Kernel\Contracts\UserResolverInterface::class,
            static function () use ($container) {
                return new \Src\Kernel\Auth\DatabaseUserResolver(
                    $container->make(\Src\Kernel\Contracts\UserRepositoryInterface::class)
                );
            },
            true
        );
    }
}

// AuthorizationInterface — o usuário pode fazer isso?
if (!$container->hasBinding(\Src\Kernel\Contracts\AuthorizationInterface::class)) {
    $container->bind(
        \Src\Kernel\Contracts\AuthorizationInterface::class,
        static function () use ($container) {
            // Reutiliza JwtAuthContext se já registrado — implementa os dois contratos
            if ($container->hasBinding(\Src\Kernel\Contracts\AuthContextInterface::class)) {
                return $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
            }
            return null;
        },
        true
    );
}

// IdentityFactoryInterface — como montar a identidade?
if (!$container->hasBinding(\Src\Kernel\Contracts\IdentityFactoryInterface::class)) {
    $container->bind(
        \Src\Kernel\Contracts\IdentityFactoryInterface::class,
        static function () use ($container) {
            // Lazy resolution: não resolve AuthorizationInterface agora, apenas quando necessário
            return new \Src\Kernel\Auth\DefaultIdentityFactory(
                // Passa uma closure que resolve sob demanda, quebrando a dependência circular
                static fn() => $container->hasBinding(\Src\Kernel\Contracts\AuthorizationInterface::class)
                    ? $container->make(\Src\Kernel\Contracts\AuthorizationInterface::class)
                    : null
            );
        },
        true
    );
}

// AuthContextInterface — orquestrador do pipeline
if (!$container->hasBinding(\Src\Kernel\Contracts\AuthContextInterface::class)) {
    if ($container->hasBinding(\Src\Kernel\Contracts\TokenValidatorInterface::class)
        && $container->hasBinding(\Src\Kernel\Contracts\UserResolverInterface::class)) {
        $container->bind(
            \Src\Kernel\Contracts\AuthContextInterface::class,
            static function () use ($container) {
                return new \Src\Kernel\Auth\JwtAuthContext(
                    $container->make(\Src\Kernel\Contracts\TokenResolverInterface::class),
                    $container->make(\Src\Kernel\Contracts\TokenValidatorInterface::class),
                    $container->make(\Src\Kernel\Contracts\UserResolverInterface::class),
                    $container->make(\Src\Kernel\Contracts\IdentityFactoryInterface::class)
                );
            },
            true
        );
    }
}
// auth.page — AuthContextInterface pré-configurado com CookieTokenResolver.
// Usado por AuthPageMiddleware e AuthCookieMiddleware para rotas de página (HTML).
// Registrado sempre que AuthContextInterface estiver disponível.
if (!$container->hasBinding('auth.page')
    && $container->hasBinding(\Src\Kernel\Contracts\AuthContextInterface::class)) {
    $container->bind(
        'auth.page',
        static function () use ($container) {
            $auth = $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
            return $auth instanceof \Src\Kernel\Auth\JwtAuthContext
                ? $auth->withResolver(new \Src\Kernel\Auth\CookieTokenResolver())
                : $auth;
        },
        true
    );
}

// AuthPageMiddleware — pré-configurado com auth.page (cookie-aware)
if (!$container->hasBinding(\Src\Kernel\Middlewares\AuthPageMiddleware::class)) {
    $container->bind(
        \Src\Kernel\Middlewares\AuthPageMiddleware::class,
        static function () use ($container) {
            try {
                $auth = $container->hasBinding('auth.page')
                    ? $container->make('auth.page')
                    : $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
            } catch (\Throwable) {
                $auth = null;
            }
            return new \Src\Kernel\Middlewares\AuthPageMiddleware($auth);
        },
        true
    );
}

// AuthCookieMiddleware — pré-configurado com auth.page (cookie-aware)
if (!$container->hasBinding(\Src\Kernel\Middlewares\AuthCookieMiddleware::class)) {
    $container->bind(
        \Src\Kernel\Middlewares\AuthCookieMiddleware::class,
        static function () use ($container) {
            try {
                $auth = $container->hasBinding('auth.page')
                    ? $container->make('auth.page')
                    : $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
            } catch (\Throwable) {
                $auth = null;
            }
            return new \Src\Kernel\Middlewares\AuthCookieMiddleware($auth);
        },
        true
    );
}

// AuthHybridMiddleware — usa CompositeTokenResolver (cookie + bearer)
// Aceita tanto cookie quanto Authorization: Bearer para máxima compatibilidade
if (!$container->hasBinding(\Src\Kernel\Middlewares\AuthHybridMiddleware::class)) {
    $container->bind(
        \Src\Kernel\Middlewares\AuthHybridMiddleware::class,
        static function () use ($container) {
            try {
                $auth = $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
                // Usa CompositeTokenResolver: tenta cookie primeiro, depois Bearer
                if ($auth instanceof \Src\Kernel\Auth\JwtAuthContext) {
                    $compositeResolver = new \Src\Kernel\Auth\CompositeTokenResolver([
                        new \Src\Kernel\Auth\CookieTokenResolver(),
                        new \Src\Kernel\Auth\BearerTokenResolver(),
                    ]);
                    $auth = $auth->withResolver($compositeResolver);
                }
            } catch (\Throwable) {
                $auth = null;
            }
            return new \Src\Kernel\Middlewares\AuthHybridMiddleware($auth);
        },
        true
    );
}
// ─────────────────────────────────────────────────────────────────────────

// Core routes
$router->get('/', [\Src\Kernel\Controllers\HomeController::class, 'index']);
$router->get('/index.php', [\Src\Kernel\Controllers\HomeController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'index'], [
    AuthPageMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->get('/dashboard/configuracoes', [DashboardController::class, 'configuracoes'], [
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
$router->get('/ide/login', [\Src\Kernel\Controllers\IdeController::class, 'login']);
$router->get('/dashboard/ide', [\Src\Kernel\Controllers\IdeController::class, 'index'], [
    static function ($request, $next) use ($container) {
        try {
            $auth = $container->hasBinding('auth.page')
                ? $container->make('auth.page')
                : $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
        } catch (\Throwable) {
            // Auth não instalado — IDE acessível sem autenticação
            return $next($request);
        }
        $mw = new \Src\Kernel\Middlewares\AuthPageMiddleware($auth);
        return $mw->handle($request, $next);
    },
    static function ($request, $next) {
        $userId = null;
        $token  = \Src\Kernel\Support\TokenExtractor::fromRequest();
        if ($token !== '') {
            try {
                $parts   = explode('.', $token);
                $payload = $parts[1] ?? '';
                $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
                $userId  = $decoded['sub'] ?? null;
            } catch (\Throwable) {}
        }
        $key = $userId ? 'page.ide:user:' . $userId : 'page.ide:ip';
        $mw  = new \Src\Kernel\Middlewares\RateLimitMiddleware(60, 60, $key);
        return $mw->handle($request, $next);
    },
]);
$router->get('/dashboard/ide/editor', [\Src\Kernel\Controllers\IdeController::class, 'editor'], [
    static function ($request, $next) use ($container) {
        try {
            $auth = $container->hasBinding('auth.page')
                ? $container->make('auth.page')
                : $container->make(\Src\Kernel\Contracts\AuthContextInterface::class);
        } catch (\Throwable) {
            return $next($request);
        }
        $mw = new \Src\Kernel\Middlewares\AuthPageMiddleware($auth);
        return $mw->handle($request, $next);
    },
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
$router->get('/api/system/modules', function () use ($modules) {
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

// Migrations status API
$router->get('/api/system/migrations/status', function () use ($container) {
    try {
        $pdo  = $container->make(\PDO::class);
        $root = __DIR__;

        $pdoModules = null;
        try { $pdoModules = $container->make('pdo.modules'); } catch (\Throwable) {}

        $migrator = new \Src\Kernel\Support\DB\Migrator($pdo, $root, $pdoModules);

        $json = $migrator->status(true) ?? '{}';
        $data = json_decode($json, true) ?? ['core' => [], 'modules' => []];
        return Response::json(['status' => 'ok', 'migrations' => $data]);
    } catch (\Throwable $e) {
        return Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Run pending migrations (admin only)
$router->post('/api/system/migrations/run', function () use ($container) {
    try {
        $pdo  = $container->make(\PDO::class);
        $root = __DIR__;
        $pdoModules = null;
        try { $pdoModules = $container->make('pdo.modules'); } catch (\Throwable) {}

        ob_start();
        $migrator = new \Src\Kernel\Support\DB\Migrator($pdo, $root, $pdoModules);
        $migrator->migrate();
        $output = ob_get_clean();

        return Response::json(['status' => 'ok', 'output' => trim($output ?: 'Migrations executadas.')]);
    } catch (\Throwable $e) {
        ob_end_clean();
        return Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}, [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Run pending seeders (admin only)
$router->post('/api/system/seeders/run', function () use ($container) {
    try {
        $pdo  = $container->make(\PDO::class);
        $root = __DIR__;
        $pdoModules = null;
        try { $pdoModules = $container->make('pdo.modules'); } catch (\Throwable) {}

        ob_start();
        $migrator = new \Src\Kernel\Support\DB\Migrator($pdo, $root, $pdoModules);
        $migrator->seed();
        $output = ob_get_clean();

        return Response::json(['status' => 'ok', 'output' => trim($output ?: 'Seeders executados.')]);
    } catch (\Throwable $e) {
        ob_end_clean();
        return Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}, [
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

/**
 * Normaliza e valida o array 'routes' retornado pelo describe() de um provider.
 *
 * Contrato esperado: cada entrada deve ser um array associativo com 'method' e 'uri'.
 * Por compatibilidade, aceita strings no formato "METHOD /uri" e as converte.
 * Entradas inválidas são descartadas com log de aviso para o dev corrigir o provider.
 *
 * @see ModuleProviderInterface::describe()
 */
function normalizeModuleRoutes(string $moduleName, array $routes): array
{
    $normalized = [];
    foreach ($routes as $route) {
        // Formato correto — objeto com method e uri
        if (is_array($route) && isset($route['method'], $route['uri'])
            && is_string($route['method']) && is_string($route['uri'])
            && $route['method'] !== '' && $route['uri'] !== '') {
            $normalized[] = $route;
            continue;
        }

        // Formato legado — string "METHOD /uri"
        if (is_string($route) && preg_match('/^(GET|POST|PUT|PATCH|DELETE)\s+(\/\S*)/i', trim($route), $m)) {
            error_log(
                "[ModuleProvider] '{$moduleName}': describe() retornou rota como string '{$route}'. " .
                "Use o formato de objeto: ['method' => '{$m[1]}', 'uri' => '{$m[2]}', 'protected' => false, 'tipo' => 'pública']. " .
                "Veja ModuleProviderInterface::describe() para o contrato completo."
            );
            $normalized[] = [
                'method'    => strtoupper($m[1]),
                'uri'       => $m[2],
                'protected' => false,
                'tipo'      => 'pública',
            ];
            continue;
        }

        // Entrada inválida — descarta e loga
        error_log(
            "[ModuleProvider] '{$moduleName}': describe() retornou entrada de rota inválida: " .
            json_encode($route) . ". Esperado: ['method' => 'GET', 'uri' => '/api/...', ...]."
        );
    }
    return $normalized;
}

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
    // Sanitiza HTTP_HOST para evitar header injection no sitemap
    $host   = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
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
        try {
            $desc = $provider->describe();
        } catch (\Throwable) {
            continue; // módulo com describe() quebrado não afeta o sitemap
        }
        $normalizedRoutes = normalizeModuleRoutes($name, $desc['routes'] ?? []);
        foreach ($normalizedRoutes as $route) {
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
(static function () use ($router, $container): void {
    $honeypotPaths = honeypotUris();
    $honeypotHandler = function () use ($container): Response {
        $ip  = \Src\Kernel\Support\IpResolver::resolve();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
        $env = $_ENV['APP_ENV'] ?? 'production';

        try {
            $scorer = $container->make(\Src\Kernel\Support\ThreatScorer::class);
        } catch (\Throwable) {
            $scorer = new \Src\Kernel\Support\ThreatScorer();
        }
        $scorer->add($ip, \Src\Kernel\Support\ThreatScorer::SCORE_HONEYPOT);

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

// Env editor (admin only)
$router->get('/api/env', [\Src\Kernel\Controllers\EnvController::class, 'index'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
$router->put('/api/env', [\Src\Kernel\Controllers\EnvController::class, 'update'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Testa conexão DB2 — usado pelo dashboard antes de salvar DEFAULT_MODULE_CONNECTION=modules
$router->post('/api/env/test-db2', function ($request) {
    try {
        // Lê credenciais do body (valores que o usuário acabou de digitar, ainda não salvos)
        // ou do $_ENV se não foram enviados
        $b    = $request->body ?? [];
        $host = trim((string) ($b['DB2_HOST']    ?? $_ENV['DB2_HOST']    ?? ''));
        $port = trim((string) ($b['DB2_PORT']    ?? $_ENV['DB2_PORT']    ?? '5432'));
        $nome = trim((string) ($b['DB2_NOME']    ?? $_ENV['DB2_NOME']    ?? ''));
        $user = trim((string) ($b['DB2_USUARIO'] ?? $_ENV['DB2_USUARIO'] ?? ''));
        // Senha: usa o valor do body se preenchido, senão usa o $_ENV atual
        $passFromBody = trim((string) ($b['DB2_SENHA'] ?? ''));
        $pass = $passFromBody !== '' ? $passFromBody : trim((string) ($_ENV['DB2_SENHA'] ?? ''));
        $drv  = strtolower(trim((string) ($b['DB2_CONEXAO'] ?? $_ENV['DB2_CONEXAO'] ?? 'postgresql')));

        if ($host === '' || $nome === '' || $user === '') {
            return Response::json([
                'ok'      => false,
                'message' => 'As configurações do banco DB2 (Host, Banco, Usuário) precisam estar preenchidas antes de selecionar esta conexão.',
            ], 422);
        }

        $driver = ($drv === 'mysql') ? 'mysql' : 'pgsql';
        $dsn    = $driver === 'pgsql'
            ? "pgsql:host={$host};port={$port};dbname={$nome}"
            : "mysql:host={$host};port={$port};dbname={$nome};charset=utf8mb4";

        $testPdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_TIMEOUT            => 5,
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $testPdo->query('SELECT 1');

        return Response::json(['ok' => true, 'message' => 'Conexão com DB2 bem-sucedida.']);
    } catch (\Throwable $e) {
        return Response::json([
            'ok'      => false,
            'message' => 'Falha ao conectar no DB2: ' . $e->getMessage(),
        ], 422);
    }
}, [AuthHybridMiddleware::class, AdminOnlyMiddleware::class]);

// ── Audit Logs API ────────────────────────────────────────────────────────
$router->get('/api/audit/logs', function ($request) use ($container) {
    try {
        $pdo = $container->make(\PDO::class);
        return (new \Src\Kernel\Controllers\AuditLogController($pdo))->listar($request);
    } catch (\Throwable $e) {
        return Response::json(['error' => $e->getMessage()], 500);
    }
}, [AuthHybridMiddleware::class, AdminOnlyMiddleware::class]);

$router->get('/api/audit/stats', function ($request) use ($container) {
    try {
        $pdo = $container->make(\PDO::class);
        return (new \Src\Kernel\Controllers\AuditLogController($pdo))->stats($request);
    } catch (\Throwable $e) {
        return Response::json(['error' => $e->getMessage()], 500);
    }
}, [AuthHybridMiddleware::class, AdminOnlyMiddleware::class]);

$router->delete('/api/audit/logs', function ($request) use ($container) {
    try {
        $pdo = $container->make(\PDO::class);
        return (new \Src\Kernel\Controllers\AuditLogController($pdo))->limpar($request);
    } catch (\Throwable $e) {
        return Response::json(['error' => $e->getMessage()], 500);
    }
}, [AuthHybridMiddleware::class, AdminOnlyMiddleware::class]);

$router->get('/api/dashboard/metrics', function () use ($container, $modules) {
    $status = [
        'host' => $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'),
        'env' => $_ENV['APP_ENV'] ?? 'local',
        'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'true' : 'false',
    ];

    $moduleList = [];
    foreach ($modules->providers() as $name => $provider) {
        try {
            $desc = $provider->describe();
        } catch (\Throwable $e) {
            error_log("[ModuleProvider] '{$name}': describe() lançou exceção: " . $e->getMessage());
            $desc = ['description' => '', 'version' => '1.0.0', 'routes' => []];
        }
        // Normaliza e valida o array 'routes' do describe()
        // Aceita objetos {method, uri} (correto) e strings "METHOD /uri" (legado)
        $desc['routes'] = normalizeModuleRoutes($name, $desc['routes'] ?? []);
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
        $repo = $container->make(\Src\Kernel\Contracts\UserRepositoryInterface::class);
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

// Instalar dependências dos módulos
$router->post('/api/modules/install-dependencies', function () {
    $controller = new \Src\Kernel\Controllers\ModulesManagementController();
    return $controller->installDependencies();
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

// Altera a conexão de banco de um módulo (escreve no connection.php)
$router->post('/api/modules/connection', function ($request) use ($modules) {
    $body = $request->body ?? [];
    $name = trim((string) ($body['name'] ?? ''));
    $conn = trim((string) ($body['connection'] ?? ''));

    if ($name === '' || !in_array($conn, ['core', 'modules', 'auto'], true)) {
        return Response::json(['error' => 'Parâmetros inválidos.'], 422);
    }

    // Valida nome do módulo — só letras e números
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
        return Response::json(['error' => 'Nome de módulo inválido.'], 422);
    }

    // Se vai usar modules, verifica se DB2 está configurado
    if ($conn === 'modules' && !\Src\Kernel\Database\PdoFactory::hasSecondaryConnection()) {
        return Response::json([
            'error' => 'DB2 não está configurado. Preencha as configurações em Banco de dados (modules) antes de usar esta conexão.',
        ], 422);
    }

    $connFile = __DIR__ . '/src/Modules/' . $name . '/Database/connection.php';

    // Cria o diretório Database se não existir
    $dir = dirname($connFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) {
            return Response::json(['error' => "Não foi possível criar o diretório Database/ para o módulo '{$name}'. Verifique as permissões de src/Modules/."], 500);
        }
    }

    // Verifica permissão de escrita antes de tentar
    if (is_file($connFile) && !is_writable($connFile)) {
        return Response::json(['error' => "Sem permissão para escrever em connection.php do módulo '{$name}'. Execute a opção 28 do menu setup para corrigir permissões."], 500);
    }
    if (!is_file($connFile) && !is_writable($dir)) {
        return Response::json(['error' => "Sem permissão para criar connection.php em '{$name}/Database/'. Execute a opção 28 do menu setup para corrigir permissões."], 500);
    }

    $content = implode("\n", [
        '<?php',
        "// Define qual banco de dados este módulo usa.",
        "// 'core'    → usa DB_* do .env (banco principal)",
        "// 'modules' → usa DB2_* do .env (banco secundário)",
        "// 'auto'    → o Kernel decide baseado na origem do módulo",
        "return '{$conn}';",
        '',
    ]);

    if (file_put_contents($connFile, $content) === false) {
        return Response::json(['error' => 'Não foi possível escrever o arquivo connection.php. Verifique as permissões da pasta src/Modules/' . $name . '/Database/.'], 500);
    }

    // Verifica que o arquivo foi escrito corretamente
    $written = @file_get_contents($connFile);
    if ($written === false || !str_contains($written, "return '{$conn}'")) {
        return Response::json(['error' => 'Arquivo escrito mas conteúdo não confere. Verifique permissões.'], 500);
    }

    // Invalida OPcache do connection.php para que o novo valor seja lido imediatamente
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($connFile, true);
    }

    // Limpa cache estático do ModuleConnectionResolver para forçar re-leitura
    \Src\Kernel\Database\ModuleConnectionResolver::clearCache();

    return Response::json(['ok' => true, 'name' => $name, 'connection' => $conn]);
}, [AuthHybridMiddleware::class, AdminOnlyMiddleware::class]);

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
