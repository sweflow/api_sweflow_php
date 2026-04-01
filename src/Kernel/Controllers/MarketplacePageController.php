<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\View;
use Src\Kernel\Http\Response\Response;

class MarketplacePageController
{
    public function index(): Response
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';

        ob_start();
        View::render('marketplace', [
            'titulo'   => 'Marketplace de Módulos',
            'logo_url' => $logoUrl,
        ]);
        $html = ob_get_clean();

        return Response::html((string)$html);
    }
}
