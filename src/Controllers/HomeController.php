<?php
namespace Src\Controllers;

use Src\View;

class HomeController
{
    public function index()
    {
        // ...debug removido...
        View::render('index', [
            'titulo' => 'Sweflow API',
            'descricao' => 'API modular PHP com detecção automática de módulos e rotas.'
        ]);
    }
}
