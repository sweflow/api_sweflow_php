<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Controllers\Concerns\RendersView;
use Src\Kernel\Http\Response\Response;

class MarketplacePageController
{
    use RendersView;

    public function index(): Response
    {
        return $this->renderView('marketplace', [
            'titulo'    => 'Marketplace de Módulos',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }
}
