<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\Http\Response\Response;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Nucleo\PluginManager;
use Src\CLI\Process;

class SystemModulesController
{
    public function __construct(
        private PluginManager $pluginManager
    ) {}

    public function search(Request $request): Response
    {
        $query   = trim($request->query['q'] ?? '');
        $page    = max(1, (int)($request->query['page'] ?? 1));
        $perPage = max(1, (int)($request->query['limit'] ?? 12));

        $localPlugins  = $this->scanLocalPlugins($query);
        $remotePlugins = $this->searchPackagist($query);

        // Merge: locais têm prioridade
        $localNames = array_column($localPlugins, 'name');
        $merged = $localPlugins;
        foreach ($remotePlugins as $remote) {
            if (!in_array($remote['name'], $localNames, true)) {
                $merged[] = $remote;
            }
        }

        // Marca status de instalação
        $installed = $this->pluginManager->read();
        foreach ($merged as &$pkg) {
            $shortName = preg_replace('/^(sweflow\/module-|sweflow\/|module-)/', '', $pkg['name']);
            // Registry stores keys as lowercase (pluginName) — check both cases
            $pkg['installed'] = isset($installed[$shortName])
                || isset($installed[strtolower($shortName)])
                || isset($installed[ucfirst($shortName)]);
            $key = $installed[$shortName] ?? $installed[strtolower($shortName)] ?? $installed[ucfirst($shortName)] ?? null;
            $pkg['enabled'] = $pkg['installed'] ? ($key['enabled'] ?? false) : false;
        }
        unset($pkg);

        $total = count($merged);
        $items = array_slice($merged, ($page - 1) * $perPage, $perPage);

        return Response::json([
            'results'    => $items,
            'pagination' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int)ceil($total / $perPage),
            ],
        ]);
    }

    private function searchPackagist(string $query): array
    {
        // Apenas o domínio packagist.org é permitido — sem SSRF possível
        $baseUrl = 'https://packagist.org';

        try {
            $context = stream_context_create(['http' => [
                'timeout'       => 6,
                'user_agent'    => 'SweflowAPI/1.0 (marketplace)',
                'ignore_errors' => true,
            ]]);

            if ($query === '' || $query === 'sweflow/module' || $query === 'sweflow') {
                $url  = $baseUrl . '/packages/list.json?vendor=sweflow';
                $json = file_get_contents($url, false, $context);
                $data = $json ? json_decode($json, true) : [];
                $names = is_array($data) ? ($data['packageNames'] ?? []) : [];

                if (empty($names)) {
                    return [];
                }

                $results = [];
                foreach (array_slice($names, 0, 20) as $name) {
                    $detail = $this->fetchPackagistDetail((string)$name, $context, $baseUrl);
                    if ($detail) $results[] = $detail;
                }
                return $results;
            }

            // Busca textual — query sanitizada, domínio fixo
            $url  = $baseUrl . '/search.json?q=' . urlencode($query) . '&vendor=sweflow&type=library';
            $json = file_get_contents($url, false, $context);
            $data = $json ? json_decode($json, true) : [];

            return array_map(fn($r) => [
                'name'        => $r['name'] ?? '',
                'description' => $r['description'] ?? '',
                'downloads'   => $r['downloads'] ?? 0,
                'url'         => $r['url'] ?? '',
                'repository'  => $r['repository'] ?? '',
            ], is_array($data) ? ($data['results'] ?? []) : []);

        } catch (\Throwable) {
            return [];
        }
    }

    private function fetchPackagistDetail(string $name, $context, string $baseUrl = 'https://packagist.org'): ?array
    {
        // Valida que o nome do pacote tem formato vendor/package (sem path traversal)
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $name)) {
            return null;
        }
        try {
            $url  = $baseUrl . '/packages/' . $name . '.json';
            $json = file_get_contents($url, false, $context);
            $data = $json ? json_decode($json, true) : [];
            $pkg  = is_array($data) ? ($data['package'] ?? null) : null;
            if (!$pkg) return null;

            return [
                'name'        => $name,
                'description' => $pkg['description'] ?? '',
                'downloads'   => $pkg['downloads']['total'] ?? 0,
                'url'         => 'https://packagist.org/packages/' . $name,
                'repository'  => $pkg['repository'] ?? '',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function uninstall(Request $request): Response
    {
        $body = $request->body;
        $package = $body['package'] ?? null;

        if (!$package) {
            return (new Response())->json(['message' => 'Pacote não informado'], 400);
        }

        try {
            $pluginName = $package;
            if (str_starts_with($package, 'sweflow/module-')) {
                $pluginName = str_replace('sweflow/module-', '', $package);
            } elseif (str_starts_with($package, 'sweflow/')) {
                $pluginName = str_replace('sweflow/', '', $package);
            }

            $shortName = ucfirst($pluginName);

            // 1. Remove do composer.json e roda composer remove se necessário
            $this->removeModuleFromComposer($shortName);
            $this->tryComposerRemove($package);

            // 2. Remove do PluginManager (registry + modules_state + arquivos)
            $this->pluginManager->uninstall($pluginName);

            // 3. Remove do capabilities
            $this->removeModuleFromCapabilities($shortName);

            // 4. Decrementa contador
            $this->decrementDownload($package);

            // 5. Regenera autoload do composer
            $this->regenerateAutoload();

            return (new Response())->json(['message' => 'Módulo removido com sucesso.']);
        } catch (\Throwable $e) {
            return (new Response())->json(['message' => 'Erro: ' . $e->getMessage()], 500);
        }
    }

    private function tryComposerRemove(string $package): void
    {
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $package)) {
            return;
        }
        $root = dirname(__DIR__, 3);
        // Only run if the package is actually in composer.json require
        $composerJson = $root . '/composer.json';
        if (!is_file($composerJson)) {
            return;
        }
        $data = json_decode((string) file_get_contents($composerJson), true) ?? [];
        if (!isset($data['require'][$package]) && !isset($data['require-dev'][$package])) {
            return; // not a composer-managed package, skip
        }
        $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';
        $proc = new Process([$composer, 'remove', $package, '--no-interaction', '--no-scripts', '--working-dir=' . $root]);
        $proc->run();
    }

    private function regenerateAutoload(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';
        $proc = new Process([$composer, 'dump-autoload', '--working-dir=' . $root]);
        $proc->run();

        // Also clear the modules cache file
        $cacheFile = $root . '/storage/modules_cache.php';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
    }

    public function install(Request $request): Response
    {
        $package = $this->getPackageFromRequest($request);

        if (!$package) {
            return $this->createErrorResponse('Pacote não informado', 400);
        }

        try {
            $pluginName = $this->resolvePluginName($package);
            $shortName = $this->getShortName($pluginName);
            $targetDir = $this->getTargetDir($shortName);

            $this->installModule($package, $pluginName, $shortName, $targetDir);

            return $this->createSuccessResponse('Módulo instalado com sucesso');
        } catch (\Throwable $e) {
            return $this->createErrorResponse('Erro: ' . $e->getMessage(), 500);
        }
    }

    private function getPackageFromRequest(Request $request): ?string
    {
        return $request->body['package'] ?? null;
    }

    private function resolvePluginName(string $package): string
    {
        if (str_starts_with($package, 'sweflow/module-')) {
            return str_replace('sweflow/module-', '', $package);
        }
        if (str_starts_with($package, 'sweflow/')) {
            return str_replace('sweflow/', '', $package);
        }
        return $package;
    }

    private function getShortName(string $pluginName): string
    {
        return ucfirst($pluginName);
    }

    private function getTargetDir(string $shortName): string
    {
        return dirname(__DIR__, 3) . '/src/Modules/' . $shortName;
    }

    private function installModule(string $package, string $pluginName, string $shortName, string $targetDir): void
    {
        // 1. Try composer require (works when the package exists on packagist and composer is available)
        if ($this->composerAvailable() && $this->tryComposerInstall($package)) {
            // Composer installed it — register in PluginManager and we're done
            $this->pluginManager->install($pluginName);
            $this->incrementDownload($package);
            return;
        }

        // 2. Fallback: create a minimal stub module in src/Modules/<ShortName>
        // This covers local/dev packages or when composer is not available
        if (!is_dir($targetDir)) {
            $this->createStubModule($targetDir, $shortName, $package);
        }

        // 3. Register in PluginManager (updates plugins_registry.json, runs migrations)
        $this->pluginManager->install($pluginName);

        // 4. Update download counter
        $this->incrementDownload($package);
    }

    private function composerAvailable(): bool
    {
        $candidates = [
            dirname(__DIR__, 3) . '/vendor/bin/composer',
            'composer',
            'composer.phar',
        ];
        foreach ($candidates as $cmd) {
            $proc = new Process([$cmd, '--version']);
            $proc->run();
            if ($proc->isSuccessful()) {
                return true;
            }
        }
        return false;
    }

    private function tryComposerInstall(string $package): bool
    {
        // Validate package name to prevent command injection
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $package)) {
            return false;
        }

        $root = dirname(__DIR__, 3);
        $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';

        $proc = new Process([$composer, 'require', $package, '--no-interaction', '--no-scripts', '--working-dir=' . $root]);
        $proc->run();
        return $proc->isSuccessful();
    }

    private function createStubModule(string $targetDir, string $shortName, string $package): void
    {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Não foi possível criar o diretório do módulo: {$targetDir}");
        }

        // Minimal composer.json so the module is discoverable
        $composerData = [
            'name'        => $package,
            'description' => "Módulo {$shortName}",
            'type'        => 'library',
            'autoload'    => ['psr-4' => ["Src\\Modules\\{$shortName}\\" => 'src/']],
            'extra'       => ['sweflow' => ['providers' => []]],
        ];
        file_put_contents(
            $targetDir . '/composer.json',
            json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Minimal Routes directory so the module loads without errors
        $routesDir = $targetDir . '/Routes';
        if (!is_dir($routesDir)) {
            mkdir($routesDir, 0755, true);
        }
        file_put_contents($routesDir . '/web.php', "<?php\n// Rotas do módulo {$shortName}\n");
    }

    private function incrementDownload(string $package): void
    {
        // Normalize to full package name
        if (!str_contains($package, '/')) {
            $package = 'sweflow/module-' . strtolower($package);
        }
        $stats = $this->loadStats();
        $stats[$package] = ($stats[$package] ?? 0) + 1;
        $this->saveStats($stats);
    }

    private function createErrorResponse(string $message, int $status): Response
    {
        return (new Response())->json(['message' => $message], $status);
    }

    private function createSuccessResponse(string $message): Response
    {
        return (new Response())->json(['message' => $message]);
    }

    private function removeModuleFromComposer(string $moduleName): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }

        $content = file_get_contents($composerPath);
        $json = json_decode($content, true);

        if (!is_array($json)) {
            return;
        }

        // Tenta remover o namespace do psr-4
        $changed = false;
        if (isset($json['autoload']['psr-4'])) {
            foreach ($json['autoload']['psr-4'] as $ns => $path) {
                // Normaliza path
                $path = str_replace('\\', '/', $path);
                // Procura por src/Modules/Email/
                // O moduleName vem capitalizado (Ex: Email)
                if (str_contains($path, "src/Modules/{$moduleName}/")) {
                    unset($json['autoload']['psr-4'][$ns]);
                    $changed = true;
                }
            }
        }
        
        // Remove também do require, caso tenha sido adicionado lá (fallback antigo ou manual)
        // O nome do pacote geralmente é sweflow/module-$moduleName (lowercase)
        $packageName = 'sweflow/module-' . strtolower($moduleName);
        if (isset($json['require'][$packageName])) {
            unset($json['require'][$packageName]);
            $changed = true;
        }

        if ($changed) {
            file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
    
    private function removeModuleFromCapabilities(string $moduleName): void
    {
        $storageDir = dirname(__DIR__, 3) . '/storage';
        $registryFile = $storageDir . '/capabilities_registry.json';
        
        if (!file_exists($registryFile)) {
            return;
        }
        
        $json = file_get_contents($registryFile);
        $map = $json ? json_decode($json, true) : [];
        $changed = false;
        
        // O plugin name salvo no registry pode variar (ex: 'sweflow-module-email', 'email', 'Email')
        // Vamos varrer e remover qualquer valor que pareça ser este módulo
        $candidates = [
            $moduleName,
            ucfirst($moduleName), // Email
            strtolower($moduleName), // email
            'sweflow-module-' . strtolower($moduleName),
            'module-' . strtolower($moduleName),
            'sweflow/module-' . strtolower($moduleName)
        ];
        
        foreach ($map as $cap => $activePlugin) {
            // Verifica se o activePlugin está na lista de candidatos
            if (in_array($activePlugin, $candidates)) {
                unset($map[$cap]);
                $changed = true;
            }
        }
        
        if ($changed) {
            file_put_contents($registryFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }


    private function scanLocalPlugins(string $query): array
    {
        // Agora buscamos em src/Modules também
        $root = dirname(__DIR__, 3) . '/src/Modules';
        if (!is_dir($root)) return [];
        
        $results = [];
        $dirs = scandir($root);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (!is_dir($root . '/' . $dir)) continue;

            $composerJson = $root . '/' . $dir . '/composer.json';
            $meta = [];
            if (file_exists($composerJson)) {
                $meta = json_decode(file_get_contents($composerJson), true) ?: [];
            }

            // Normaliza nome
            $name = $meta['name'] ?? 'sweflow/module-' . strtolower($dir);
            $desc = $meta['description'] ?? 'Módulo do Sistema';
            
            // Filtro
            if ($query && 
                stripos($name, $query) === false && 
                stripos($desc, $query) === false &&
                stripos($dir, $query) === false
            ) {
                continue;
            }

            // Ignora módulos de sistema protegidos (Auth, Usuario) para não poluir o Marketplace
            if (in_array(strtolower($dir), ['auth', 'usuario'])) {
                continue;
            }

            $results[] = [
                'name' => $name,
                'description' => $desc . ' (src/Modules)',
                'downloads' => $this->getDownloadCount($name),
                'url' => '',
                'repository' => ''
            ];
        }
        return $results;
    }

    private function getDownloadCount(string $moduleName): int
    {
        $stats = $this->loadStats();
        return $stats[$moduleName] ?? 0;
    }


    private function decrementDownload(string $moduleName): void
    {
        // Normalize name
        if (str_starts_with($moduleName, 'sweflow/module-')) {
            $moduleName = 'sweflow/module-' . str_replace('sweflow/module-', '', $moduleName);
        } elseif (!str_contains($moduleName, '/')) {
            // Assume short name like 'email', convert to package name
            $moduleName = 'sweflow/module-' . strtolower($moduleName);
        }

        $stats = $this->loadStats();
        if (isset($stats[$moduleName]) && $stats[$moduleName] > 0) {
            $stats[$moduleName]--;
            $this->saveStats($stats);
        }
    }

    private function loadStats(): array
    {
        $file = dirname(__DIR__, 3) . '/storage/marketplace_stats.json';
        if (!file_exists($file)) {
            return [];
        }
        $json = file_get_contents($file);
        return $json ? (json_decode($json, true) ?? []) : [];
    }

    private function saveStats(array $stats): void
    {
        $file = dirname(__DIR__, 3) . '/storage/marketplace_stats.json';
        file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT));
    }
}
