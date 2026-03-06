<?php
namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Nucleo\PluginManager;
use Src\Kernel\Support\DB\PluginMigrator;

class PluginInstallCommand
{
    public function handle(string $pluginName): void
    {
        $pdo = PdoFactory::fromEnv();
        $migrator = new PluginMigrator($pdo, dirname(__DIR__, 2));
        $manager = new PluginManager($migrator, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');
        $manager->install($pluginName);
        echo "✔ plugin instalado: $pluginName\n";
    }
}
