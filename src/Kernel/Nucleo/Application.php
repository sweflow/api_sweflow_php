<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Http\Request\RequestFactory;
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
        $this->container = $container;
        $this->router = $router;
        $this->modules = $modules;
        $this->exceptionHandler = new Handler();
        
        // Inicializa Contexto da Requisição (SaaS Pillar 1)
        $this->context = new RequestContext();
        $this->container->bind(RequestContext::class, $this->context, true); // Singleton por request
        
        // Inicializa Logger Estruturado (SaaS Pillar 3)
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
        // Ajuste de path: dirname(__DIR__, 2) sai de Src/Nucleo -> Src -> Raiz -> src/Modules
        // Mas a estrutura é src/Modules. O path correto é dirname(__DIR__, 2) . '/Modules'
        // dirname(__DIR__) = src/Kernel
        // dirname(__DIR__, 2) = src
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

            // Bloqueia HTTP quando COOKIE_SECURE=true e COOKIE_HTTPONLY=true
            if (\Src\Kernel\Support\CookieConfig::requiresHttps() && !\Src\Kernel\Support\CookieConfig::isHttps()) {
                $enforcer = new \Src\Kernel\Middlewares\HttpsEnforcerMiddleware();
                $enforcer->handle($request, fn($r) => null)->Enviar();
                return;
            }

            $response = $this->router->dispatch($request);

            // Observabilidade: loga 401, 403, 429 automaticamente para Fail2Ban e análise
            $statusCode = $response->getStatusCode();
            if (in_array($statusCode, [401, 403, 429], true)) {
                try {
                    $audit = $this->container->make(\Src\Kernel\Support\AuditLogger::class);
                    $uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                    $audit->registrarResposta($statusCode, $uri);
                } catch (\Throwable) {
                    // Falha silenciosa — observabilidade não deve quebrar a resposta
                }
            }

            $response->Enviar();
        } catch (\Throwable $e) {
            $this->exceptionHandler->handle($e);
        }
    }
}
