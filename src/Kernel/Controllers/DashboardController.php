<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Controllers\Concerns\RendersView;
use Src\Kernel\Http\Response\Response;

class DashboardController
{
    use RendersView;

    public function index(): Response
    {
        return $this->renderView('dashboard', [
            'titulo'    => 'Dashboard da API',
            'descricao' => 'Monitoramento em tempo real do núcleo da API.',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }

    public function configuracoes(): Response
    {
        return $this->renderView('configuracoes', [
            'titulo'    => 'Configurações — Vupi.us API',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }
}
