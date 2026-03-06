<?php
namespace Src\CLI;

class MakeModuleCommand
{
    public function handle(string $name): void
    {
        $base = __DIR__ . "/../Modules/$name";
        $dirs = [
            "Controllers",
            "Services",
            "Repositories",
            "Entities",
            "Routes",
            "Database/Migrations",
            "Database/Seeders"
        ];
        foreach ($dirs as $dir) {
            $path = $base . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
        $this->createRoutes($name, $base);
        $this->createProvider($name, $base);
        $this->createComposer($name);
        echo "✔ Módulo $name criado com sucesso\n";
    }

    private function createRoutes(string $name, string $base): void
    {
        $content = "<?php\n";
        file_put_contents("$base/Routes/web.php", $content);
    }

    private function createProvider(string $name, string $base): void
    {
        $ns = "SweflowModules\\\\" . $name;
        $content = "<?php\nnamespace $ns;\nclass ModuleProvider\n{\n    public function register(\$app){}\n    public function boot(\$app){}\n}\n";
        file_put_contents("$base/ModuleProvider.php", $content);
    }

    private function createComposer(string $name): void
    {
        $package = strtolower($name);
        $composer = [
            "name" => "sweflow/module-" . $package,
            "type" => "library",
            "autoload" => [
                "psr-4" => [
                    "SweflowModules\\\\$name\\\\" => "src/Modules/$name/"
                ]
            ],
            "extra" => [
                "sweflow" => [
                    "providers" => [
                        "SweflowModules\\\\$name\\\\ModuleProvider"
                    ]
                ]
            ],
            "require" => [
                "php" => ">=8.1"
            ]
        ];
        file_put_contents(
            __DIR__ . "/../../module-" . $package . "-composer.json",
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
