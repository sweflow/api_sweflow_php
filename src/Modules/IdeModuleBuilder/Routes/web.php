<?php

use Src\Modules\IdeModuleBuilder\Controllers\IdeProjectController;
use Src\Modules\IdeModuleBuilder\Controllers\DatabaseConnectionController;
use Src\Modules\IdeModuleBuilder\Controllers\DatabaseStatusController;
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
$router->get('/api/ide/dashboard',                   [IdeProjectController::class, 'dashboard'],    $protected);
$router->post('/api/ide/projects',                   [IdeProjectController::class, 'create'],       $createProtected);
$router->get('/api/ide/projects/{id}',               [IdeProjectController::class, 'get'],          $protected);
$router->delete('/api/ide/projects/{id}',            [IdeProjectController::class, 'delete'],       $protected);
$router->put('/api/ide/projects/{id}/files',         [IdeProjectController::class, 'saveFile'],     $protected);
$router->put('/api/ide/projects/{id}/folders',       [IdeProjectController::class, 'saveFolders'],  $protected);
$router->delete('/api/ide/projects/{id}/files',      [IdeProjectController::class, 'deleteFile'],   $protected);
$router->post('/api/ide/projects/{id}/deploy',       [IdeProjectController::class, 'deploy'],       $protected);
$router->get('/api/ide/projects/{id}/status',        [IdeProjectController::class, 'status'],       $protected);
$router->post('/api/ide/projects/{id}/migrate',      [IdeProjectController::class, 'migrate'],              $protected);
$router->post('/api/ide/projects/{id}/validate-migrations', [IdeProjectController::class, 'preValidateMigrations'], $protected);
$router->post('/api/ide/projects/{id}/seed',         [IdeProjectController::class, 'seed'],         $protected);
$router->delete('/api/ide/projects/{id}/module',     [IdeProjectController::class, 'removeModule'], $protected);
$router->patch('/api/ide/projects/{id}/module',      [IdeProjectController::class, 'toggleModule'], $protected);
$router->delete('/api/ide/projects/{id}/tables',     [IdeProjectController::class, 'dropTables'],   $protected);
$router->post('/api/ide/scaffold',                   [IdeProjectController::class, 'scaffold'],     $protected);
$router->get('/api/ide/constraints',                 [IdeProjectController::class, 'constraints'],   $protected);
$router->get('/api/ide/check-module/{name}',         [IdeProjectController::class, 'checkModuleName'], $protected);
$router->post('/api/ide/projects/{id}/analyze',      [IdeProjectController::class, 'analyze'],       $protected);
$router->get('/api/ide/projects/{id}/dependencies',  [IdeProjectController::class, 'dependencyHealth'],    $protected);
$router->post('/api/ide/projects/{id}/dependencies', [IdeProjectController::class, 'installDependencies'],  $protected);
$router->post('/api/ide/projects/{id}/lint',         [IdeProjectController::class, 'lint'],          $protected);
$router->post('/api/ide/projects/{id}/autofix',      [IdeProjectController::class, 'autofix'],      $protected);
$router->post('/api/ide/projects/{id}/run',          [IdeProjectController::class, 'run'],          $runProtected);
$router->post('/api/ide/projects/{id}/debug',        [IdeProjectController::class, 'debugFile'],    $runProtected);
$router->post('/api/ide/projects/{id}/terminal',     [IdeProjectController::class, 'terminal'],     $runProtected);
$router->get('/api/ide/my-limits',                   [IdeProjectController::class, 'myLimits'],     $protected);
$router->get('/api/ide/user-limit/{userId}',         [IdeProjectController::class, 'getUserLimit'], $admin);
$router->put('/api/ide/user-limit/{userId}',         [IdeProjectController::class, 'setUserLimit'], $admin);

// Conexões de banco de dados personalizadas
$router->get('/api/ide/database-connections',                    [DatabaseConnectionController::class, 'index'],         $protected);
$router->post('/api/ide/database-connections/test',              [DatabaseConnectionController::class, 'test'],          $protected);
$router->post('/api/ide/database-connections',                   [DatabaseConnectionController::class, 'store'],         $protected);
$router->put('/api/ide/database-connections/{id}',               [DatabaseConnectionController::class, 'update'],        $protected);
$router->post('/api/ide/database-connections/{id}/activate',     [DatabaseConnectionController::class, 'activate'],      $protected);
$router->post('/api/ide/database-connections/deactivate-all',    [DatabaseConnectionController::class, 'deactivateAll'], $protected);
$router->delete('/api/ide/database-connections/{id}',            [DatabaseConnectionController::class, 'destroy'],       $protected);

// Status da conexão de banco de dados (migrations pendentes e tabelas)
$router->get('/api/ide/database-status',                         [DatabaseStatusController::class, 'status'],            $protected);
