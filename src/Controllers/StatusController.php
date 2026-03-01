<?php
namespace Src\Controllers;

use Src\Nucleo\InfoServidor;
use Src\Nucleo\LeitorModulos;
use Src\Nucleo\Resposta;

class StatusController
{
    public function __construct(
        private InfoServidor $servidor,
        private LeitorModulos $modulos,
        private Resposta $resposta
    ) {}

    public function index(): void
    {
        $this->resposta->json([
            'status' => $this->servidor->obter(),
            'modules' => $this->modulos->ler()
        ]);
    }
}
