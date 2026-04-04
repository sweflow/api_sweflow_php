<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require 'vendor/autoload.php';

$modulesPath = __DIR__ . '/src/Modules';
echo 'Path: ' . $modulesPath . PHP_EOL;
echo 'Exists: ' . (is_dir($modulesPath) ? 'yes' : 'no') . PHP_EOL;

$modules = scandir($modulesPath);
foreach ($modules as $module) {
    if ($module === '.' || $module === '..') continue;
    $moduleDir = $modulesPath . '/' . $module;
    echo 'Module: ' . $module . ' - dir: ' . (is_dir($moduleDir) ? 'yes' : 'no') . PHP_EOL;
}

// Simula o ModuleLoader
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$container = new Src\Kernel\Nucleo\Container();
$container->bind(Src\Kernel\Contracts\ContainerInterface::class, $container, true);

try {
    $container->bind(PDO::class, static fn() => Src\Kernel\Database\PdoFactory::fromEnv(), true);
    $migrator = new Src\Kernel\Support\DB\PluginMigrator(Src\Kernel\Database\PdoFactory::fromEnv(), __DIR__);
    $container->bind(Src\Kernel\Support\DB\PluginMigrator::class, $migrator, true);
} catch (Throwable $e) {
    echo 'DB error: ' . $e->getMessage() . PHP_EOL;
}

$manager = new Src\Kernel\Nucleo\PluginManager(
    $container->make(Src\Kernel\Support\DB\PluginMigrator::class),
    __DIR__ . '/storage'
);
$container->bind(Src\Kernel\Nucleo\PluginManager::class, $manager, true);

$loader = new Src\Kernel\Nucleo\ModuleLoader($container);
$loader->discover($modulesPath);

echo 'Providers: ' . count($loader->providers()) . PHP_EOL;
foreach ($loader->providers() as $name => $provider) {
    echo '  - ' . $name . ' (enabled: ' . ($loader->isEnabled($name) ? 'true' : 'false') . ')' . PHP_EOL;
}
