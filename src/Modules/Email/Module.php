<?php

namespace Src\Modules\Email;

use Src\Contracts\ContainerInterface;
use Src\Contracts\ModuleProviderInterface;
use Src\Contracts\RouterInterface;
use Src\Modules\Email\Controllers\EmailController;
use Src\Modules\Email\Services\EmailService;
use Src\Middlewares\AdminOnlyMiddleware;
use Src\Middlewares\AuthHybridMiddleware;

class Module implements ModuleProviderInterface
{
    public function boot(ContainerInterface $container): void
    {
        $container->bind(EmailService::class, fn() => new EmailService(), true);
    }

    public function registerRoutes(RouterInterface $router): void
    {
        $router->post('/api/email/custom', [EmailController::class, 'sendCustom'], [
            AuthHybridMiddleware::class,
            AdminOnlyMiddleware::class,
        ]);
    }

    public function describe(): array
    {
        return [
            'name' => 'Email',
            'routes' => [
                ['method' => 'POST', 'uri' => '/api/email/custom', 'tipo' => 'privada'],
            ],
        ];
    }
}
