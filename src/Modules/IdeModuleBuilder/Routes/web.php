<?php

use Src\Modules\IdeModuleBuilder\Controllers\IdeProjectController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$protected = [AuthHybridMiddleware::class];
$admin = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Rate limit para criação de projetos: 5 por minuto por usuário
$createProtected = [AuthHybridMiddleware::class, [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60, 'key' => 'ide.create']]];
// Rate limit para execução de código: 30 por minuto
$runProtected = [AuthHybridMiddleware::class, [RateLimitMiddleware::class, ['limit' => 30, 'window' => 60, 'key' => 'ide.run']]];

$router->get('/api/ide/projects',                    [IdeProjectController::class, 'list'],         $protected);
$router->post('/api/ide/projects',                   [IdeProjectController::class, 'create'],       $createProtected);
$router->get('/api/ide/projects/{id}',               [IdeProjectController::class, 'get'],          $protected);
$router->delete('/api/ide/projects/{id}',            [IdeProjectController::class, 'delete'],       $protected);
$router->put('/api/ide/projects/{id}/files',         [IdeProjectController::class, 'saveFile'],     $protected);
$router->put('/api/ide/projects/{id}/folders',       [IdeProjectController::class, 'saveFolders'],  $protected);
$router->delete('/api/ide/projects/{id}/files',      [IdeProjectController::class, 'deleteFile'],   $protected);
$router->post('/api/ide/projects/{id}/deploy',       [IdeProjectController::class, 'deploy'],       $protected);
$router->get('/api/ide/projects/{id}/status',        [IdeProjectController::class, 'status'],       $protected);
$router->post('/api/ide/projects/{id}/migrate',      [IdeProjectController::class, 'migrate'],      $protected);
$router->post('/api/ide/projects/{id}/seed',         [IdeProjectController::class, 'seed'],         $protected);
$router->delete('/api/ide/projects/{id}/module',     [IdeProjectController::class, 'removeModule'], $protected);
$router->patch('/api/ide/projects/{id}/module',      [IdeProjectController::class, 'toggleModule'], $protected);
$router->delete('/api/ide/projects/{id}/tables',     [IdeProjectController::class, 'dropTables'],   $protected);
$router->post('/api/ide/scaffold',                   [IdeProjectController::class, 'scaffold'],     $protected);
$router->get('/api/ide/constraints',                 [IdeProjectController::class, 'constraints'],   $protected);
$router->get('/api/ide/check-module/{name}',         [IdeProjectController::class, 'checkModuleName'], $protected);
$router->post('/api/ide/projects/{id}/analyze',      [IdeProjectController::class, 'analyze'],       $protected);
$router->post('/api/ide/projects/{id}/lint',         [IdeProjectController::class, 'lint'],          $protected);
$router->post('/api/ide/projects/{id}/autofix',      [IdeProjectController::class, 'autofix'],      $protected);
$router->post('/api/ide/projects/{id}/run',          [IdeProjectController::class, 'run'],          $runProtected);
$router->post('/api/ide/projects/{id}/debug',        [IdeProjectController::class, 'debugFile'],    $runProtected);
$router->post('/api/ide/projects/{id}/terminal',     [IdeProjectController::class, 'terminal'],     $runProtected);
$router->get('/api/ide/my-limits',                   [IdeProjectController::class, 'myLimits'],     $protected);
$router->get('/api/ide/user-limit/{userId}',         [IdeProjectController::class, 'getUserLimit'], $admin);
$router->put('/api/ide/user-limit/{userId}',         [IdeProjectController::class, 'setUserLimit'], $admin);
