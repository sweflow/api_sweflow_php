<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Controllers\Concerns\RendersView;
use Src\Kernel\Http\Response\Response;

class UsuariosPageController
{
    use RendersView;

    public function index(): Response
    {
        return $this->renderView('usuarios', [
            'titulo'    => 'Gerenciar Usuários',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }
}
