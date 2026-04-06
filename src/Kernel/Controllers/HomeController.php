<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\View;
use Src\Kernel\Http\Response\Response;

class HomeController
{
    public function index(): Response
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? (getenv('APP_LOGO_URL') ?: null);
        if ($logoUrl !== null) {
            $ext = strtolower(pathinfo(parse_url($logoUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if ($ext === 'ico' || $ext === '') {
                $logoUrl = null;
            }
        }

        ob_start();
        View::render('index', [
            'titulo'    => 'Sweflow API',
            'descricao' => 'API modular com detecção automática de módulos e rotas.',
            'logo_url'  => $logoUrl,
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
        $html = ob_get_clean();

        return Response::html((string)$html);
    }
}
