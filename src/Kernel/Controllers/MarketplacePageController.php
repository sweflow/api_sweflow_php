<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\View;

class MarketplacePageController
{
    public function index(): void
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';
        View::render('marketplace', [
            'titulo' => 'Marketplace de Módulos',
            'logo_url' => $logoUrl,
        ]);
    }
}
