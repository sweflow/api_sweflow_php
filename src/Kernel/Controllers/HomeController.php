<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Controllers\Concerns\RendersView;
use Src\Kernel\Http\Response\Response;

class HomeController
{
    use RendersView;

    public function index(): Response
    {
        return $this->renderView('index', [
            'titulo'    => 'Vupi.us API',
            'descricao' => 'API modular com detecção automática de módulos e rotas.',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }
}
