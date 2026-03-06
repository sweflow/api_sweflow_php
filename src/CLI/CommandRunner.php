<?php
namespace Src\CLI;

class CommandRunner
{
    public function run(array $argv): void
    {
        $command = $argv[1] ?? null;
        if (!$command) {
            echo "Sweflow CLI\n";
            echo "Comandos disponíveis:\n";
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
            return;
        }
        switch ($command) {
            case 'make:module':
                $name = $argv[2] ?? null;
                if (!$name) {
                    echo "Informe o nome do módulo\n";
                    return;
                }
                (new MakeModuleCommand())->handle($name);
                break;
            case 'make:plugin':
                $name = $argv[2] ?? null;
                if (!$name) {
                    echo "Informe o nome do plugin\n";
                    return;
                }
                $opts = [];
                for ($i = 3; $i < count($argv); $i++) {
                    $arg = $argv[$i] ?? '';
                    if (str_starts_with($arg, '--')) {
                        $kv = explode('=', substr($arg, 2), 2);
                        $k = $kv[0] ?? '';
                        $v = $kv[1] ?? '';
                        if ($k !== '') {
                            $opts[$k] = $v;
                        }
                    }
                }
                (new MakePluginCommand())->handle($name, $opts);
                break;
            case 'plugin:inspect':
                (new PluginInspectCommand())->handle();
                break;
            case 'plugin:migrate':
                (new PluginMigrateCommand())->handle();
                break;
            case 'plugin:rollback':
                $name = $argv[2] ?? null;
                (new PluginRollbackCommand())->handle($name);
                break;
            case 'plugin:validate':
                (new PluginValidateCommand())->handle();
                break;
            case 'plugin:install':
                $name = $argv[2] ?? null;
                if (!$name) { echo "Informe o nome do plugin (ex.: email)\n"; return; }
                (new PluginInstallCommand())->handle($name);
                break;
            case 'plugin:enable':
            case 'plugin:disable':
            case 'plugin:uninstall':
                $action = $command;
                $name = $argv[2] ?? null;
                if (!$name) { echo "Informe o nome do plugin\n"; return; }
                $pdo = \Src\Kernel\Database\PdoFactory::fromEnv();
                $migrator = new \Src\Kernel\Support\DB\PluginMigrator($pdo, dirname(__DIR__, 2));
                $manager = new \Src\Kernel\Nucleo\PluginManager($migrator, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');
                if ($action === 'plugin:enable') $manager->enable($name);
                if ($action === 'plugin:disable') $manager->disable($name);
                if ($action === 'plugin:uninstall') $manager->uninstall($name);
                echo "✔ $action $name\n";
                break;
            case 'capability:list':
                $cap = $argv[2] ?? null;
                (new CapabilityListCommand())->handle($cap);
                break;
            case 'plugin:provider:set':
                $cap = $argv[2] ?? null;
                $name = $argv[3] ?? null;
                if (!$cap || !$name) { echo "Uso: plugin:provider:set <capability> <plugin>\n"; return; }
                (new PluginProviderSetCommand())->handle($cap, $name);
                break;
            default:
                echo "Comando não encontrado\n";
        }
    }
}
