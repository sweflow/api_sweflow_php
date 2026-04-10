<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Controllers\Concerns\RendersView;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class IdeController
{
    use RendersView;

    /** GET /ide/login — página de login da IDE (qualquer usuário) */
    public function login(Request $request): Response
    {
        // Se já está autenticado, redireciona direto para a IDE
        $token = \Src\Kernel\Support\TokenExtractor::fromRequest();
        if ($token !== '') {
            try {
                [$payload] = \Src\Kernel\Support\JwtDecoder::decodeUser($token);
                if (isset($payload->tipo) && $payload->tipo === 'user') {
                    return new Response('', 302, ['Location' => '/dashboard/ide']);
                }
            } catch (\Throwable) {
                // Token inválido — exibe login normalmente
            }
        }

        return $this->renderView('ide-login', [
            'titulo'    => 'Vupi.us IDE — Login',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }

    /** GET /dashboard/ide — página de listagem de projetos */
    public function index(): Response
    {
        return $this->renderView('ide-projects', [
            'titulo'    => 'Projetos — Vupi.us IDE',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }

    /** GET /dashboard/ide/editor — IDE do projeto */
    public function editor(): Response
    {
        return $this->renderView('ide', [
            'titulo'    => 'Vupi.us IDE',
            'logo_url'  => $this->resolveLogoUrl(),
            'csp_nonce' => \Src\Kernel\Nonce::get(),
        ]);
    }
}
