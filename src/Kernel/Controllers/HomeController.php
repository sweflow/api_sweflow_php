<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\View;

class HomeController
{
    public function index()
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';

        View::render('index', [
            'titulo' => 'Sweflow API',
            'descricao' => 'API modular PHP com detecção automática de módulos e rotas.',
            'logo_url' => $logoUrl
        ]);
    }
}
