<?php
namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\PluginMigrator;

class PluginMigrateCommand
{
    public function handle(): void
    {
        $pdo = PdoFactory::fromEnv();
        $runner = new PluginMigrator($pdo, dirname(__DIR__, 2));
        $runner->migrateAll();
    }
}
