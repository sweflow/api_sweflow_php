<?php

use Src\Modules\Documentacao\Controllers\DocumentacaoController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$router->get('/doc', [DocumentacaoController::class, 'index']);
// Rotas específicas para cada tipo de asset
$router->get('/doc/assets/css/{file}', [DocumentacaoController::class, 'assetCss']);
$router->get('/doc/assets/js/{file}', [DocumentacaoController::class, 'assetJs']);
$router->get('/doc/assets/imgs/{file}', [DocumentacaoController::class, 'assetImg']);
