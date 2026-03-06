<?php
namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\PluginMigrator;

class PluginRollbackCommand
{
    public function handle(?string $pluginName = null): void
    {
        $pdo = PdoFactory::fromEnv();
        $runner = new PluginMigrator($pdo, dirname(__DIR__, 2));
        $runner->rollbackLatest($pluginName);
    }
}
