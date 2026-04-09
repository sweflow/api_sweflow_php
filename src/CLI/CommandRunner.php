<?php
namespace Src\CLI;

use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\PluginMigrator;
use Src\Kernel\Nucleo\PluginManager;

class CommandRunner
{
    public function run(array $argv): int
    {
        $command = $argv[0] ?? null;
        if (!$command) {
            $this->printHelp();
            return 0;
        }
        return $this->dispatch($command, $argv);
    }

    private function printHelp(): void
    {
        echo "Vupi.us CLI\n";
        echo "Comandos disponíveis:\n";
        echo "  setup [--auto] [--db-mode=docker|skip] [--server=php|pm2]\n";
        echo "  migrate [--seed] [--rollback] [--core] [--modules] [--status] [--status --json]\n";
        echo "  make:module Nome\n";
        echo "  make:plugin Nome\n";
        echo "  plugin:inspect\n";
        echo "  plugin:migrate\n";
        echo "  plugin:rollback [plugin]\n";
        echo "  plugin:validate\n";
        echo "  plugin:install <plugin>\n";
        echo "  plugin:enable <plugin>\n";
        echo "  plugin:disable <plugin>\n";
        echo "  plugin:uninstall <plugin>\n";
        echo "  capability:list [capability]\n";
        echo "  plugin:provider:set <capability> <plugin>\n";
    }

    private function dispatch(string $command, array $argv): int
    {
        if ($command === 'migrate') {
            return (new MigrateCommand())->run(array_slice($argv, 1));
        }

        $handled = match (true) {
            $command === 'setup'           => (new SetupCommand())->handle($argv),
            $command === 'make:module'     => $this->handleMakeModule($argv),
            $command === 'make:plugin'     => $this->handleMakePlugin($argv),
            $command === 'plugin:inspect'  => (new PluginInspectCommand())->handle(),
            $command === 'plugin:migrate'  => (new PluginMigrateCommand())->handle(),
            $command === 'plugin:rollback' => (new PluginRollbackCommand())->handle($argv[2] ?? null),
            $command === 'plugin:validate' => (new PluginValidateCommand())->handle(),
            $command === 'plugin:install'  => $this->handlePluginInstall($argv),
            in_array($command, ['plugin:enable', 'plugin:disable', 'plugin:uninstall'], true)
                                           => $this->handlePluginLifecycle($command, $argv),
            $command === 'capability:list'     => (new CapabilityListCommand())->handle($argv[2] ?? null),
            $command === 'plugin:provider:set' => $this->handleProviderSet($argv),
            default => null,
        };

        if ($handled === null) {
            echo "Comando não encontrado: {$command}\n";
            return 1;
        }

        return 0;
    }

    private function handleMakeModule(array $argv): void
    {
        $name = $argv[2] ?? null;
        if (!$name) { echo "Informe o nome do módulo\n"; return; }
        (new MakeModuleCommand())->handle($name);
    }

    private function handleMakePlugin(array $argv): void
    {
        $name = $argv[2] ?? null;
        if (!$name) { echo "Informe o nome do plugin\n"; return; }
        $opts = [];
        for ($i = 3, $c = count($argv); $i < $c; $i++) {
            $arg = $argv[$i] ?? '';
            if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$k, $v] = explode('=', substr($arg, 2), 2);
                if ($k !== '') $opts[$k] = $v;
            }
        }
        (new MakePluginCommand())->handle($name, $opts);
    }

    private function handlePluginInstall(array $argv): void
    {
        $name = $argv[2] ?? null;
        if (!$name) { echo "Informe o nome do plugin (ex.: email)\n"; return; }
        (new PluginInstallCommand())->handle($name);
    }

    private function handlePluginLifecycle(string $action, array $argv): void
    {
        $name = $argv[2] ?? null;
        if (!$name) { echo "Informe o nome do plugin\n"; return; }
        $pdo      = PdoFactory::fromEnv();
        $migrator = new PluginMigrator($pdo, dirname(__DIR__, 2));
        $manager  = new PluginManager($migrator, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');
        if ($action === 'plugin:enable')    $manager->enable($name);
        if ($action === 'plugin:disable')   $manager->disable($name);
        if ($action === 'plugin:uninstall') $manager->uninstall($name);
        echo "✔ $action $name\n";
    }

    private function handleProviderSet(array $argv): void
    {
        $cap  = $argv[2] ?? null;
        $name = $argv[3] ?? null;
        if (!$cap || !$name) { echo "Uso: plugin:provider:set <capability> <plugin>\n"; return; }
        (new PluginProviderSetCommand())->handle($cap, $name);
    }
}
