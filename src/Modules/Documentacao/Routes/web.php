<?php

use Src\Modules\Documentacao\Controllers\DocumentacaoController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$router->get('/doc', [DocumentacaoController::class, 'index']);
$router->get('/doc/assets/css/{file}', [DocumentacaoController::class, 'asset']);
$router->get('/doc/assets/js/{file}', [DocumentacaoController::class, 'asset']);
$router->get('/doc/assets/imgs/{file}', [DocumentacaoController::class, 'asset']);
