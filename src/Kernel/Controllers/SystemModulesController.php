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
            return Response::json(['message' => 'Pacote não informado'], 400);
        }

        try {
            $pluginName = $package;
            if (str_starts_with($package, 'sweflow/module-')) {
                $pluginName = str_replace('sweflow/module-', '', $package);
            } elseif (str_starts_with($package, 'sweflow/')) {
                $pluginName = str_replace('sweflow/', '', $package);
            }

            $shortName = ucfirst($pluginName);

            // 1. Remove do PluginManager (registry + modules_state)
            $this->pluginManager->uninstall($pluginName);

            // 2. Remove do capabilities
            $this->removeModuleFromCapabilities($shortName);

            // 3. Remove namespace e require do composer.json do projeto
            $this->removeModuleFromComposer($shortName, $package);

            // 4. Remove TODOS os diretórios do módulo (src/Modules/, vendor/, clone local)
            $this->removeAllModuleDirectories($pluginName, $shortName);

            // 5. Roda composer remove para limpar installed.json
            $this->tryComposerRemove($package);

            // 6. Decrementa contador
            $this->decrementDownload($package);

            // 7. Regenera autoload e limpa cache
            $this->regenerateAutoload();

            return Response::json(['message' => 'Módulo removido com sucesso.']);
        } catch (\Throwable $e) {
            return Response::json(['message' => 'Erro: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove todos os diretórios onde o módulo pode estar instalado.
     */
    private function removeAllModuleDirectories(string $pluginName, string $shortName): void
    {
        $root       = dirname(__DIR__, 3);
        $simpleName = strtolower($pluginName);

        $candidates = [
            // src/Modules/Email
            $root . '/src/Modules/' . $shortName,
            // vendor/sweflow/module-email
            $root . '/vendor/sweflow/module-' . $simpleName,
            // vendor/sweflow/email
            $root . '/vendor/sweflow/' . $simpleName,
            // module-email/ (clone local na raiz)
            $root . '/module-' . $simpleName,
            // plugins/sweflow-module-email
            $root . '/plugins/sweflow-module-' . $simpleName,
            // plugins/email
            $root . '/plugins/' . $simpleName,
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                $this->pluginManager->deleteDirectoryPublic($dir);
            }
        }
    }

    private function removeLocalCloneDir(string $pluginName): void
    {
        $root = dirname(__DIR__, 3);
        $simpleName = str_replace(['sweflow-module-', 'module-'], '', strtolower($pluginName));

        // Possible local clone directory names
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'module-' . $simpleName,
            $root . DIRECTORY_SEPARATOR . $simpleName,
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            // Safety: must contain composer.json with matching package name
            $composerFile = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            if (!is_file($composerFile)) {
                continue;
            }
            $meta = json_decode((string) file_get_contents($composerFile), true) ?? [];
            $name = $meta['name'] ?? '';
            if (!str_contains($name, $simpleName)) {
                continue;
            }
            $this->pluginManager->deleteDirectoryPublic($dir);
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
            $shortName  = $this->getShortName($pluginName);
            $targetDir  = $this->getTargetDir($shortName);

            $this->installModule($package, $pluginName, $shortName, $targetDir);

            return $this->createSuccessResponse('Módulo instalado com sucesso');
        } catch (\RuntimeException $e) {
            return $this->createErrorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->createErrorResponse('Erro ao instalar: ' . $e->getMessage(), 500);
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
        // Módulo já instalado em src/Modules/ — apenas registra
        if (is_dir($targetDir)) {
            $this->installModuleDependencies($targetDir);
            $this->pluginManager->install($pluginName);
            $this->incrementDownload($package);
            return;
        }

        // 1. Tenta baixar via composer download (sem adicionar ao require do projeto)
        //    Usa git clone como estratégia principal para src/Modules/
        $repoUrl = $this->resolvePackageRepo($package);

        if ($repoUrl && $this->gitAvailable()) {
            $this->gitCloneToModules($repoUrl, $targetDir);
            $this->installModuleDependencies($targetDir);
            $this->regenerateAutoload();
            $this->pluginManager->install($pluginName);
            $this->incrementDownload($package);
            return;
        }

        // 2. Fallback: composer require (instala em vendor/, mas funciona)
        if ($this->composerAvailable()) {
            if ($this->tryComposerInstall($package)) {
                // Copia de vendor/ para src/Modules/ para seguir o padrão
                $vendorPath = dirname(__DIR__, 3) . '/vendor/sweflow/module-' . strtolower($pluginName);
                if (is_dir($vendorPath)) {
                    $this->copyModuleToModules($vendorPath, $targetDir);
                    // Remove do vendor após copiar
                    $this->tryComposerRemove($package);
                    $this->removeModuleFromComposer($shortName, $package);
                }
                $this->installModuleDependencies($targetDir);
                $this->regenerateAutoload();
                $this->pluginManager->install($pluginName);
                $this->incrementDownload($package);
                return;
            }
            throw new \RuntimeException(
                "Falha ao instalar '{$package}'. " .
                "Verifique se o pacote existe no Packagist e a conexão com a internet."
            );
        }

        throw new \RuntimeException(
            "Não foi possível instalar '{$package}'. " .
            "Instale o composer (https://getcomposer.org) ou git e tente novamente."
        );
    }

    /**
     * Lê o composer.json do módulo instalado em src/Modules/<Nome>/
     * e instala as dependências PHP necessárias no projeto principal.
     */
    private function installModuleDependencies(string $moduleDir): void
    {
        $composerFile = $moduleDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            return;
        }

        $meta = json_decode((string) file_get_contents($composerFile), true);
        if (!is_array($meta)) {
            return;
        }

        // Registra o namespace do módulo no autoload do projeto principal
        $this->registerModuleAutoload($moduleDir, $meta);

        $require = $meta['require'] ?? [];
        if (empty($require)) {
            return;
        }

        if (!$this->composerAvailable()) {
            error_log("[Marketplace] Composer não disponível — dependências do módulo não instaladas.");
            return;
        }

        $root     = dirname(__DIR__, 3);
        $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';

        // Filtra dependências que não são do próprio projeto (php, ext-*, etc.)
        $toInstall = [];
        foreach ($require as $dep => $version) {
            if ($dep === 'php' || str_starts_with($dep, 'ext-') || str_starts_with($dep, 'lib-')) {
                continue;
            }
            // Verifica se já está instalado no projeto
            $installedPath = $root . '/vendor/composer/installed.json';
            if (is_file($installedPath)) {
                $installed = json_decode((string) file_get_contents($installedPath), true) ?? [];
                $packages  = $installed['packages'] ?? $installed;
                $names     = array_column(is_array($packages) ? $packages : [], 'name');
                if (in_array($dep, $names, true)) {
                    continue; // já instalado
                }
            }
            $toInstall[] = $dep . ':' . $version;
        }

        if (empty($toInstall)) {
            return;
        }

        @set_time_limit(300);

        $cmd = array_merge(
            [$composer, 'require'],
            $toInstall,
            ['--no-interaction', '--no-scripts', '--working-dir=' . $root]
        );

        $proc = new Process($cmd);
        if (!$proc->run()) {
            error_log("[Marketplace] Falha ao instalar dependências do módulo: " . $proc->getOutput());
            throw new \RuntimeException(
                "Módulo instalado, mas falha ao instalar dependências: " . implode(', ', $toInstall) .
                "\nErro: " . $proc->getOutput()
            );
        }
    }

    /**
     * Registra o namespace PSR-4 do módulo no composer.json do projeto principal
     * e regenera o autoload, para que classes do módulo sejam encontradas.
     */
    private function registerModuleAutoload(string $moduleDir, array $moduleMeta): void
    {
        $root         = dirname(__DIR__, 3);
        $composerPath = $root . '/composer.json';
        if (!is_file($composerPath)) {
            return;
        }

        $psr4 = $moduleMeta['autoload']['psr-4'] ?? [];
        if (empty($psr4)) {
            return;
        }

        $projectComposer = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($projectComposer)) {
            return;
        }

        $changed = false;
        $relBase = 'src/Modules/' . basename($moduleDir) . '/';

        foreach ($psr4 as $namespace => $srcPath) {
            // Normaliza o path relativo ao projeto
            $relPath = $relBase . ltrim(str_replace(['\\', '/'], '/', $srcPath), '/');
            $relPath = rtrim($relPath, '/') . '/';

            $existing = $projectComposer['autoload']['psr-4'][$namespace] ?? null;
            if ($existing !== $relPath) {
                $projectComposer['autoload']['psr-4'][$namespace] = $relPath;
                $changed = true;
            }
        }

        if ($changed) {
            file_put_contents(
                $composerPath,
                json_encode($projectComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
            // Regenera autoload para que as classes sejam encontradas imediatamente
            if ($this->composerAvailable()) {
                $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';
                $proc = new Process([$composer, 'dump-autoload', '--working-dir=' . $root]);
                $proc->run();
            }
        }
    }

    /**
     * Copia um módulo de vendor/ para src/Modules/ adaptando o namespace.
     */
    private function copyModuleToModules(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($sourceDir)) {
            return;
        }
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($sourceDir) + 1);
            $dest     = $targetDir . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function resolvePackageRepo(string $package): ?string
    {
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $package)) {
            return null;
        }
        try {
            $context = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'SweflowAPI/1.0']]);
            $json = @file_get_contents("https://packagist.org/packages/{$package}.json", false, $context);
            if (!$json) return null;
            $data = json_decode($json, true);
            return $data['package']['repository'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function gitAvailable(): bool
    {
        $proc = new Process(['git', '--version']);
        $proc->run();
        return $proc->isSuccessful();
    }

    private function gitCloneToModules(string $repoUrl, string $targetDir): void
    {
        if (is_dir($targetDir)) {
            return; // already exists
        }
        $proc = new Process(['git', 'clone', '--depth=1', $repoUrl, $targetDir]);
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new \RuntimeException("Falha ao clonar repositório: {$repoUrl}");
        }
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
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $package)) {
            return false;
        }

        $root     = dirname(__DIR__, 3);
        $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';

        // Give composer enough time — web requests default to 30s which is too short
        $prevLimit = (int) ini_get('max_execution_time');
        @set_time_limit(300);

        $proc = new Process([
            $composer, 'require', $package,
            '--no-interaction',
            '--no-scripts',
            '--no-plugins',
            '--working-dir=' . $root,
        ]);
        $ok = $proc->run();

        if ($prevLimit > 0) {
            @set_time_limit($prevLimit);
        }

        if (!$ok) {
            error_log("[Marketplace] composer require {$package} failed: " . $proc->getOutput());
        }

        return $ok;
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
        return Response::json(['message' => $message], $status);
    }

    private function createSuccessResponse(string $message): Response
    {
        return Response::json(['message' => $message]);
    }

    private function removeModuleFromComposer(string $moduleName, string $packageName = ''): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }

        $json = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($json)) {
            return;
        }

        $changed = false;
        $pkgLower = 'sweflow/module-' . strtolower($moduleName);
        $pkgFull  = $packageName ?: $pkgLower;

        // Remove from require
        foreach ([$pkgFull, $pkgLower] as $pkg) {
            if (isset($json['require'][$pkg])) {
                unset($json['require'][$pkg]);
                $changed = true;
            }
        }

        // Remove from autoload psr-4
        if (isset($json['autoload']['psr-4'])) {
            foreach ($json['autoload']['psr-4'] as $ns => $path) {
                $path = str_replace('\\', '/', $path);
                if (str_contains($path, "src/Modules/{$moduleName}/")) {
                    unset($json['autoload']['psr-4'][$ns]);
                    $changed = true;
                }
            }
        }

        // Remove path repository entries pointing to this module
        if (isset($json['repositories']) && is_array($json['repositories'])) {
            $simpleName = strtolower($moduleName);
            $filtered = array_values(array_filter($json['repositories'], function ($repo) use ($simpleName, $pkgFull) {
                if (($repo['type'] ?? '') !== 'path') {
                    return true; // keep non-path repos
                }
                $url = str_replace('\\', '/', $repo['url'] ?? '');
                // Remove if the path contains the module name
                return !str_contains(strtolower($url), $simpleName)
                    && !str_contains(strtolower($url), str_replace('sweflow/', '', strtolower($pkgFull)));
            }));
            if (count($filtered) !== count($json['repositories'])) {
                $json['repositories'] = $filtered ?: null;
                if (empty($json['repositories'])) {
                    unset($json['repositories']);
                }
                $changed = true;
            }
        }

        if ($changed) {
            file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
