<?php
namespace Src\CLI;

class PluginValidateCommand
{
    public function handle(): void
    {
        $root = dirname(__DIR__, 2);
        $pluginsRoot = $root . DIRECTORY_SEPARATOR . 'plugins';
        $plugins = $this->getPluginDirectories($pluginsRoot);
        if (empty($plugins)) {
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

    private function getPluginDirectories(string $pluginsRoot): array
    {
        if (!is_dir($pluginsRoot)) {
            return [];
        }
        return array_values(array_filter(scandir($pluginsRoot), fn($d) => !in_array($d, ['.', '..'])));
    }

    private function validateOne(string $path): void
    {
        $name = basename($path);
        $errors = [];
        $warnings = [];

        $composerResult = $this->loadComposerMeta($path);
        if (isset($composerResult['error'])) {
            $errors[] = $composerResult['error'];
        } else {
            $meta = $composerResult['meta'];
            $this->validatePackageName($meta, $warnings);
            $this->validateProvidersConfig($meta, $path, $errors, $warnings);
        }

        $this->displayResults($name, $errors, $warnings);
    }

    private function loadComposerMeta(string $path): array
    {
        $composer = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composer)) {
            return ['error' => "composer.json ausente"];
        }
        $content = @file_get_contents($composer);
        $meta = json_decode($content, true);
        if (!is_array($meta)) {
            return ['error' => "composer.json inválido"];
        }
        return ['meta' => $meta];
    }

    private function validatePackageName(array $meta, array &$warnings): void
    {
        $pkg = $meta['name'] ?? '';
        if (!$pkg || !str_starts_with($pkg, 'sweflow/')) {
            $warnings[] = "composer.name deve começar com 'sweflow/'";
        }
    }

    private function validateProvidersConfig(array $meta, string $path, array &$errors, array &$warnings): void
    {
        $extraProviders = $meta['extra']['sweflow']['providers'] ?? null;
        if (!is_array($extraProviders) || count($extraProviders) === 0) {
            $errors[] = "extra.sweflow.providers não definido";
            return;
        }
        foreach ($extraProviders as $prov) {
            if (!$this->classFileLikelyExists($path, $prov)) {
                $warnings[] = "Provider '$prov' não encontrado sob src/";
            }
        }
    }

    private function displayResults(string $name, array $errors, array $warnings): void
    {
        echo "Plugin: $name\n";
        foreach ($errors as $error) {
            echo "Error: $error\n";
        }
        foreach ($warnings as $warning) {
            echo "Warning: $warning\n";
        }
    }
}
        }

        $pluginJson = $path . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($pluginJson)) {
            $warnings[] = "plugin.json ausente (recomendado para capabilities)";
        } else {
            $pj = json_decode(@file_get_contents($pluginJson), true) ?: [];
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
