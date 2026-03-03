<?php
namespace Src\Controllers;

use Src\View;

class DashboardController
{
    public function index(): void
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';

        View::render('dashboard', [
            'titulo' => 'Dashboard da API',
            'descricao' => 'Monitoramento em tempo real do núcleo da API.',
            'logo_url' => $logoUrl,
        ]);
    }
}
