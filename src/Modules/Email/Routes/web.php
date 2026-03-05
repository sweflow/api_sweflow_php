<?php

use Src\Modules\Email\Controllers\EmailController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$router->post('/api/email/custom', [EmailController::class, 'sendCustom'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);
