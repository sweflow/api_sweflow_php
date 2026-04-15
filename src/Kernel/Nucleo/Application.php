<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Request\RequestFactory;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Exceptions\Handler;
use Src\Kernel\Support\RequestContext;
use Src\Kernel\Support\Logger;

class Application
{
    private ContainerInterface $container;
    private RouterInterface $router;
    private ModuleLoader $modules;
    private Handler $exceptionHandler;
    private RequestContext $context;
    private Logger $logger;

    public function __construct(ContainerInterface $container, RouterInterface $router, ModuleLoader $modules)
    {
        $this->container        = $container;
        $this->router           = $router;
        $this->modules          = $modules;
        $this->exceptionHandler = new Handler();

        $this->context = new RequestContext();
        $this->container->bind(RequestContext::class, $this->context, true);

        $this->logger = new Logger($this->context);
        $this->container->bind(Logger::class, $this->logger, true);
    }

    public function boot(): void
    {
        // 1. Registra Handler Global
        set_exception_handler([$this->exceptionHandler, 'handle']);

        // Log de Boot
        $this->logger->debug('Application booting...', ['env' => $_ENV['APP_ENV'] ?? 'unknown']);

        // 2. Boot dos módulos (Serviços, Rotas)
        // dirname(__DIR__) = src/Kernel
        // dirname(__DIR__, 2) = src
        // dirname(__DIR__, 2) . '/Modules' = src/Modules
        $this->modules->discover(dirname(__DIR__, 2) . '/Modules');
        $this->modules->bootAll();
        $this->modules->registerRoutes($this->router);
    }

    public function router(): RouterInterface
    {
        return $this->router;
    }

    public function run(): void
    {
        try {
            $request = RequestFactory::fromGlobals();

            // ── Divergence guard (dev only) ───────────────────────────────
            // Detecta se RequestContext e CookieConfig divergem em isSecure/isHttps.
            // Em produção é silencioso; em dev loga no stderr para diagnóstico imediato.
            if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
                $ctxSecure    = $this->context->isSecure();
                $cookieSecure = \Src\Kernel\Support\CookieConfig::isHttps();
                if ($ctxSecure !== $cookieSecure) {
                    error_log(sprintf(
                        '[DIVERGENCE] RequestContext::isSecure()=%s vs CookieConfig::isHttps()=%s | HTTPS=%s XFP=%s REMOTE=%s TRUST=%s APP_URL=%s',
                        $ctxSecure    ? 'true' : 'false',
                        $cookieSecure ? 'true' : 'false',
                        $_SERVER['HTTPS']                    ?? '-',
                        $_SERVER['HTTP_X_FORWARDED_PROTO']   ?? '-',
                        $_SERVER['REMOTE_ADDR']              ?? '-',
                        $_ENV['TRUST_PROXY']                 ?? '-',
                        $_ENV['APP_URL']                     ?? '-',
                    ));
                }
            }

            // Bloqueia HTTP quando COOKIE_SECURE=true e COOKIE_HTTPONLY=true
            if (\Src\Kernel\Support\CookieConfig::requiresHttps() && !$this->context->isSecure()) {
                $enforcer = new \Src\Kernel\Middlewares\HttpsEnforcerMiddleware();
                $enforcer->handle($request, fn($r) => null)->send();
                return;
            }

            // Bloqueia bots maliciosos por User-Agent antes de qualquer processamento
            $threatScorer = null;
            try {
                $threatScorer = $this->container->make(\Src\Kernel\Support\ThreatScorer::class);
            } catch (\Throwable) {}
            $botBlocker  = new \Src\Kernel\Middlewares\BotBlockerMiddleware($threatScorer);
            $botResponse = $botBlocker->handle($request, static fn(Request $r) => new Response('', 200));
            if ($botResponse->getStatusCode() === 403) {
                $botResponse->send();
                return;
            }

            // Injeta SecurityHeadersMiddleware globalmente — garante headers em TODAS as respostas,
            // incluindo 404, 405, erros de rota e respostas que escapem do pipeline de middlewares.
            $secHeaders = new \Src\Kernel\Middlewares\SecurityHeadersMiddleware();
            $response   = $secHeaders->handle($request, fn($r) => $this->router->dispatch($r));

            // Observabilidade: loga 401, 403, 429 automaticamente para Fail2Ban e análise
            $statusCode = $response->getStatusCode();
            if (in_array($statusCode, [401, 403, 429], true)) {
                try {
                    $audit = $this->container->make(\Src\Kernel\Support\AuditLogger::class);
                    $uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

                    // Enriquece o contexto com o identifier da tentativa de login (sem expor senha)
                    $contextoExtra = [];
                    $rawBody = file_get_contents('php://input');
                    if ($rawBody !== false && $rawBody !== '') {
                        $body = json_decode($rawBody, true) ?: [];
                        $identifier = $body['login'] ?? $body['identifier'] ?? $body['email'] ?? $body['username'] ?? '';
                        if ($identifier !== '') {
                            // Trunca e mascara parcialmente para não expor dados completos
                            $identifier = mb_substr((string) $identifier, 0, 64);
                            $contextoExtra['identifier'] = $identifier;
                        }
                    }

                    $audit->registrarResposta($statusCode, $uri, null, $contextoExtra);
                } catch (\Throwable) {
                    // Falha silenciosa — observabilidade não deve quebrar a resposta
                }
            }

            $response->send();
        } catch (\Throwable $e) {
            $this->exceptionHandler->handle($e);
        }
    }
}
