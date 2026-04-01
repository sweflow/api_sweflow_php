<?php
namespace Src\CLI;

class PluginValidateCommand
{
    public function handle(): void
    {
        $root = dirname(__DIR__, 2);
        $pluginsRoot = $root . DIRECTORY_SEPARATOR . 'plugins';
        $plugins = is_dir($pluginsRoot)
            ? array_values(array_filter(scandir($pluginsRoot), fn($d) => !in_array($d, ['.', '..'])))
            : [];
        if (!$plugins) {
            echo "Nenhum plugin local encontrado em plugins/\n";
            return;
        }
        foreach ($plugins as $dir) {
            $path = $pluginsRoot . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $this->validateOne($path);
        }
    }

    private function validateOne(string $path): void
    {
        $name = basename($path);
        $errors = [];
        $warnings = [];

        [$composerErrors, $composerWarnings] = $this->processComposer($path);
        $errors = array_merge($errors, $composerErrors);
        $warnings = array_merge($warnings, $composerWarnings);

        $this->printResults($name, $errors, $warnings);
    }

    private function processComposer(string $path): array
    {
        $errors = [];
        $warnings = [];
        $composerPath = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerPath)) {
            $errors[] = "composer.json ausente";
        } else {
            $meta = $this->loadComposerMeta($composerPath);
            $warnings = array_merge($warnings, $this->validatePkgName($meta));
            [$provErrors, $provWarnings] = $this->validateProviders($path, $meta);
            $errors = array_merge($errors, $provErrors);
            $warnings = array_merge($warnings, $provWarnings);
        }
        return [$errors, $warnings];
    }

    private function loadComposerMeta(string $composerPath): array
    {
        $content = @file_get_contents($composerPath);
        $meta = json_decode($content, true);
        return is_array($meta) ? $meta : [];
    }

    private function validatePkgName(array $meta): array
    {
        $warnings = [];
        $pkg = $meta['name'] ?? '';
        if (!$pkg || !str_starts_with($pkg, 'sweflow/')) {
            $warnings[] = "composer.name deve começar com 'sweflow/'";
        }
        return $warnings;
    }

    private function validateProviders(string $path, array $meta): array
    {
        $errors = [];
        $warnings = [];
        $extraProviders = $meta['extra']['sweflow']['providers'] ?? null;
        if (!is_array($extraProviders) || count($extraProviders) === 0) {
            $errors[] = "extra.sweflow.providers não definido";
        } else {
            foreach ($extraProviders as $prov) {
                if (!$this->classFileLikelyExists($path, $prov)) {
                    $warnings[] = "Provider '$prov' não encontrado sob src/";
                }
            }
        }
        return [$errors, $warnings];
    }

    private function printResults(string $name, array $errors, array $warnings): void
    {
        if (empty($errors) && empty($warnings)) {
            echo "Plugin '$name' validado com sucesso.\n";
        } else {
            echo "Problemas encontrados para plugin '$name':\n";
            foreach ($errors as $error) {
                echo "  Erro: $error\n";
            }
            foreach ($warnings as $warning) {
                echo "  Aviso: $warning\n";
            }
        }
    }

    private function classFileLikelyExists(string $path, string $class): bool
    {
        // assuming existing implementation
    }
}
        }

        $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($pluginJson)) {
            $warnings[] = "plugin.json ausente (recomendado para capabilities)";
        } else {
            if (false !== ($content = file_get_contents($pluginJson))) {
                $pj = json_decode($content, true) ?: [];
            } else {
                $pj = [];
                $warnings[] = "plugin.json não pôde ser lido";
            }
            if (!isset($pj['provides']) || !is_array($pj['provides'])) {
                $warnings[] = "plugin.json: 'provides' ausente ou inválido";
            }
        }

        $migrations = $path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrations)) {
            $warnings[] = "Migrations ausentes (src/Database/Migrations)";
        }

        $seeders = $path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders';
        if (!is_dir($seeders)) {
            $warnings[] = "Seeders ausentes (src/Database/Seeders)";
        }

        $routes = $path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'routes.php';
        if (!is_file($routes)) {
            $warnings[] = "Routes ausentes (src/Routes/routes.php)";
        }

        echo "Validando: $name\n";
        if ($errors) {
            foreach ($errors as $e) echo "  [ERRO] $e\n";
        } else {
            echo "  Estrutura básica OK\n";
        }
        foreach ($warnings as $w) {
            echo "  [AVISO] $w\n";
        }
        echo "\n";
    }

    private function classFileLikelyExists(string $pluginPath, string $fqcn): bool
    {
        $parts = explode('\\', ltrim($fqcn, '\\'));
        // Assume PSR-4 "SweflowModules\\X\\..." mapped to "src/"
        $relative = implode(DIRECTORY_SEPARATOR, array_slice($parts, 2)) . '.php';
        $file = $pluginPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative;
        return is_file($file);
    }
}
