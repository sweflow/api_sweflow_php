<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\View;
use Src\Kernel\Http\Response\Response;

class HomeController
{
    public function index(): Response
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';

        ob_start();
        View::render('index', [
            'titulo'    => 'Sweflow API',
            'descricao' => 'API modular PHP com detecção automática de módulos e rotas.',
            'logo_url'  => $logoUrl,
        ]);
        $html = ob_get_clean();

        return Response::html((string)$html);
    }
}
