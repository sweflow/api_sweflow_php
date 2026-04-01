<?php
namespace Src\CLI;

class MakePluginCommand
{
    public function handle(string $name, array $options = []): void
    {
        $slug = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
        $capability = $options['capability'] ?? ($slug . "-capability");
        $description = $options['description'] ?? ("Integração com {$name} e capability {$capability}.");
        $pluginDir = __DIR__ . "/../../plugins/sweflow-module-$slug";
        $dirs = [
            "src/Providers",
            "src/Routes",
            "src/Services",
            "src/Database/Migrations",
            "src/Database/Seeders"
        ];
        foreach ($dirs as $dir) {
            $path = $pluginDir . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
        $this->createComposer($pluginDir, $name, $slug);
        $this->createProvider($pluginDir, $name);
        $this->createRoutes($pluginDir, $name);
        $this->createService($pluginDir, $name);
        $this->createPluginJson($pluginDir, $name, $slug, $capability);
        $this->createReadme($pluginDir, $name, $slug, $capability, $description);
        $this->createLicense($pluginDir);
        echo "✔ Plugin sweflow-module-$slug criado\n";
    }

    private function createComposer(string $dir, string $name, string $slug): void
    {
        $composer = [
            "name" => "sweflow/module-$slug",
            "description" => "Sweflow module $name",
            "type" => "library",
            "autoload" => [
                "psr-4" => [
                    "SweflowModules\\\\$name\\\\" => "src/"
                ]
            ],
            "extra" => [
                "sweflow" => [
                    "providers" => [
                        "SweflowModules\\\\$name\\\\{$name}ServiceProvider"
                    ]
                ]
            ],
            "require" => [
                "php" => ">=8.1"
            ]
        ];
        file_put_contents(
            $dir . "/composer.json",
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function createProvider(string $dir, string $name): void
    {
        $ns = "SweflowModules\\\\" . $name;
        $class = $name . "ServiceProvider";
        $content = "<?php\nnamespace $ns;\nuse Src\\Kernel\\Contracts\\ContainerInterface;\nuse Src\\Kernel\\Contracts\\ModuleProviderInterface;\nuse Src\\Kernel\\Contracts\\RouterInterface;\nclass $class implements ModuleProviderInterface\n{\n    public function registerRoutes(RouterInterface \$router): void\n    {\n        \$file = __DIR__ . '/../Routes/routes.php';\n        if (is_file(\$file)) {\n            \$r = \$router;\n            unset(\$router);\n            \$router = \$r;\n            require \$file;\n        }\n    }\n    public function boot(ContainerInterface \$container): void\n    {\n    }\n    public function describe(): array\n    {\n        return [];\n    }\n    public function onInstall(): void {}\n    public function onEnable(): void {}\n    public function onDisable(): void {}\n    public function onUninstall(): void {}\n}\n";
        file_put_contents("$dir/src/Providers/$class.php", $content);
    }

    private function createRoutes(string $dir, string $name): void
    {
        $content = "<?php\nuse Src\\Kernel\\Http\\Response\\Response;\n\$router->get('/" . strtolower($name) . "/ping', function () { return Response::json(['status'=>'ok','module'=>'" . strtolower($name) . "']); });\n";
        file_put_contents("$dir/src/Routes/routes.php", $content);
    }

    private function createService(string $dir, string $name): void
    {
        $ns = "SweflowModules\\\\" . $name;
        $class = $name . "Service";
        $content = "<?php\nnamespace $ns;\nclass $class\n{\n    public function status(): string\n    {\n        return 'ok';\n    }\n}\n";
        file_put_contents("$dir/src/Services/$class.php", $content);
    }

    private function createPluginJson(string $dir, string $name, string $slug, string $capability): void
    {
        $plugin = [
            "name" => strtolower($name),
            "version" => "1.0.0",
            "provides" => [$capability],
            "requires" => [],
            "conflicts" => []
        ];
        file_put_contents("$dir/plugin.json", json_encode($plugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function createReadme(string $dir, string $name, string $slug, string $capability, string $description): void
    {
        $tpl = [];
        $tpl[] = "Sweflow {{PLUGIN_NAME}} Plugin";
        $tpl[] = "";
        $tpl[] = "Plugin {{PLUGIN_NAME}} para a plataforma Sweflow API.";
        $tpl[] = "";
        $tpl[] = "{{DESCRIPTION}}";
        $tpl[] = "";
        $tpl[] = "Funcionalidades";
        $tpl[] = "- Integração com {{PLUGIN_NAME}}";
        $tpl[] = "- Capability {{CAPABILITY}}";
        $tpl[] = "- Rotas API para testes";
        $tpl[] = "- Migrations versionadas";
        $tpl[] = "- Integração com sistema de plugins Sweflow";
        $tpl[] = "";
        $tpl[] = "Instalação";
        $tpl[] = "";
        $tpl[] = "composer require sweflow/module-{{PLUGIN_SLUG}}";
        $tpl[] = "";
        $tpl[] = "Depois rode as migrations:";
        $tpl[] = "";
        $tpl[] = "php db migrate";
        $tpl[] = "";
        $tpl[] = "Ou apenas as migrations de plugins:";
        $tpl[] = "";
        $tpl[] = "php sweflow plugin:migrate";
        $tpl[] = "";
        $tpl[] = "Configuração (.env)";
        $tpl[] = "Adicione as variáveis no .env:";
        $tpl[] = "";
        $tpl[] = "{{PLUGIN_SLUG}}_API_KEY=";
        $tpl[] = "{{PLUGIN_SLUG}}_SECRET=";
        $tpl[] = "";
        $tpl[] = "Uso na API";
        $tpl[] = "";
        $tpl[] = "O Sweflow utiliza injeção por contratos/capabilities.";
        $tpl[] = "";
        $tpl[] = "Rotas";
        $tpl[] = "GET /{{PLUGIN_SLUG}}/ping";
        $tpl[] = "";
        $tpl[] = "Migrations";
        $tpl[] = "";
        $tpl[] = "src/Database/Migrations";
        $tpl[] = "- 1.0.0";
        $tpl[] = "  - create_{{PLUGIN_SLUG}}_table.php";
        $tpl[] = "";
        $tpl[] = "Capabilities";
        $tpl[] = "- provides: [\"{{CAPABILITY}}\"]";
        $tpl[] = "";
        $tpl[] = "Desenvolvimento local";
        $tpl[] = "composer require sweflow/module-{{PLUGIN_SLUG}}:*";
        $tpl[] = "";
        $tpl[] = "Atualizações";
        $tpl[] = "composer update sweflow/module-{{PLUGIN_SLUG}}";
        $tpl[] = "php sweflow plugin:migrate";
        $tpl[] = "";
        $tpl[] = "Rollback";
        $tpl[] = "php sweflow plugin:rollback {{PLUGIN_SLUG}}";
        $tpl[] = "";
        $tpl[] = "Licença";
        $tpl[] = "MIT License";
        $content = implode("\n", $tpl);
        $replacements = [
            '{{PLUGIN_NAME}}' => $name,
            '{{PLUGIN_SLUG}}' => $slug,
            '{{CAPABILITY}}' => $capability,
            '{{DESCRIPTION}}' => $description ?: "Integração com {$name} e capability {$capability}.",
        ];
        $content = strtr($content, $replacements);
        file_put_contents("$dir/README.md", $content);
    }

    private function createLicense(string $dir): void
    {
        $year = date('Y');
        $content = "MIT License\n\nCopyright (c) $year\n\nPermission is hereby granted, free of charge, to any person obtaining a copy\nof this software and associated documentation files (the \"Software\"), to deal\nin the Software without restriction, including without limitation the rights\nto use, copy, modify, merge, publish, distribute, sublicense, and/or sell\ncopies of the Software, and to permit persons to whom the Software is\nfurnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all\ncopies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\nIMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\nFITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\nAUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\nLIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\nOUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\nSOFTWARE.\n";
        file_put_contents("$dir/LICENSE", $content);
    }
}
