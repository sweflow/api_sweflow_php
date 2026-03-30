<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\View;

/**
 * Renderiza o dashboard administrativo.
 * A autorização é feita pelos middlewares AuthHybridMiddleware + AdminOnlyMiddleware
 * registrados na rota — este controller não precisa verificar auth diretamente.
 */
class DashboardController
{
    public function index(): void
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';

        View::render('dashboard', [
            'titulo'    => 'Dashboard da API',
            'descricao' => 'Monitoramento em tempo real do núcleo da API.',
            'logo_url'  => $logoUrl,
        ]);
    }
}
