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

        // Merge: locais têm prioridade, mas downloads vêm do Packagist quando disponível
        $localNames = array_column($localPlugins, 'name');
        $remoteByName = [];
        foreach ($remotePlugins as $remote) {
            $remoteByName[$remote['name']] = $remote;
        }

        $merged = [];
        foreach ($localPlugins as $local) {
            // Se o pacote existe no Packagist, usa os downloads reais de lá
            if (isset($remoteByName[$local['name']])) {
                $local['downloads'] = $remoteByName[$local['name']]['downloads'];
            }
            $merged[] = $local;
        }
        foreach ($remotePlugins as $remote) {
            if (!in_array($remote['name'], $localNames, true)) {
                $merged[] = $remote;
            }
        }

        // Marca status de instalação
        $installed = $this->pluginManager->read();
        $modulesRoot = dirname(__DIR__, 3) . '/src/Modules';
        foreach ($merged as &$pkg) {
            // Remove qualquer prefixo vendor/module- para obter o nome curto
            $shortName = preg_replace('/^[^\/]+\/(?:module-)?/', '', $pkg['name']);

            // 1. Verifica no registry (instalado via marketplace)
            $inRegistry = isset($installed[$shortName])
                || isset($installed[strtolower($shortName)])
                || isset($installed[ucfirst($shortName)]);

            // 2. Verifica se o diretório existe em src/Modules/ (instalado manualmente ou via CLI)
            $inModulesDir = is_dir($modulesRoot . '/' . ucfirst($shortName))
                || is_dir($modulesRoot . '/' . $shortName)
                || is_dir($modulesRoot . '/' . strtolower($shortName));

            $pkg['installed'] = $inRegistry || $inModulesDir;

            $key = $installed[$shortName] ?? $installed[strtolower($shortName)] ?? $installed[ucfirst($shortName)] ?? null;
            $pkg['enabled'] = $pkg['installed'] ? ($key['enabled'] ?? true) : false;
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

            // Busca vazia ou genérica — lista todos os pacotes do vendor sweflow
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

            $results = [];
            foreach (is_array($data) ? ($data['results'] ?? []) : [] as $r) {
                $name = $r['name'] ?? '';
                if ($name === '') continue;
                // Busca detalhes individuais para pegar downloads reais do Packagist
                $detail = $this->fetchPackagistDetail($name, $context, $baseUrl);
                $results[] = $detail ?? [
                    'name'        => $name,
                    'description' => $r['description'] ?? '',
                    'downloads'   => $r['downloads'] ?? 0,
                    'url'         => $r['url'] ?? '',
                    'repository'  => $r['repository'] ?? '',
                ];
            }
            return $results;

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
        $body    = $request->body;
        $package = trim((string) ($body['package'] ?? ''));

        if ($package === '') {
            return Response::json(['message' => 'Pacote não informado.'], 400);
        }

        // Valida formato: vendor/package (ex: vupi.us/module-email)
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $package)) {
            return Response::json(['message' => 'Formato de pacote inválido. Use: vendor/package.'], 422);
        }

        try {
            $pluginName = $this->resolvePluginName($package);
            $shortName  = $this->getShortName($pluginName);

            // 1. Remove do PluginManager (registry + modules_state)
            $this->pluginManager->uninstall($pluginName);

            // 2. Remove do capabilities
            $this->removeModuleFromCapabilities($shortName);

            // 3. Remove namespace e require do composer.json do projeto
            $this->removeModuleFromComposer($shortName, $package);

            // 4. Remove TODOS os diretórios do módulo (src/Modules/, vendor/, clone local)
            $this->removeAllModuleDirectories($pluginName, $shortName, $package);

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
    private function removeAllModuleDirectories(string $pluginName, string $shortName, string $package = ''): void
    {
        $root       = dirname(__DIR__, 3);
        $simpleName = strtolower($pluginName);
        $vendor     = $package !== '' && str_contains($package, '/') ? explode('/', $package)[0] : null;

        $candidates = [
            // src/Modules/Email
            $root . '/src/Modules/' . $shortName,
            // module-email/ (clone local na raiz)
            $root . '/module-' . $simpleName,
            // plugins/email
            $root . '/plugins/' . $simpleName,
        ];

        // Adiciona paths específicos do vendor do pacote
        if ($vendor) {
            $candidates[] = $root . '/vendor/' . $vendor . '/module-' . $simpleName;
            $candidates[] = $root . '/vendor/' . $vendor . '/' . $simpleName;
            $candidates[] = $root . '/plugins/' . $vendor . '-module-' . $simpleName;
        }

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                $this->pluginManager->deleteDirectoryPublic($dir);
            }
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
        $cacheFile = $root . '/storage/modules_cache.json';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }

        // Recarrega PHP-FPM para que o novo autoloader seja lido pelos workers em execução.
        // Tenta os sockets/serviços mais comuns — falha silenciosa se não encontrar.
        $this->reloadPhpFpm();
    }

    private function reloadPhpFpm(): void
    {
        $pidFile = '/run/php/php8.2-fpm.pid';

        // Estratégia 1: SIGUSR2 direto ao master process via PID file (graceful reload)
        if (function_exists('posix_kill') && defined('SIGUSR2') && is_file($pidFile)) {
            $pid = trim((string) @file_get_contents($pidFile));
            if ($pid !== '' && ctype_digit($pid)) {
                @posix_kill((int) $pid, SIGUSR2);
                return;
            }
        }

        // Estratégia 2: systemctl reload (requer sudo sem senha configurado)
        foreach (['php8.2-fpm', 'php8.1-fpm', 'php8.0-fpm', 'php-fpm'] as $service) {
            $proc = new Process(['sudo', '-n', 'systemctl', 'reload', $service]);
            if ($proc->run()) {
                return;
            }
        }

        // Estratégia 3: pkill -USR2 (último recurso)
        (new Process(['pkill', '-USR2', '-f', 'php-fpm']))->run();
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
        $package = trim((string) ($request->body['package'] ?? ''));
        if ($package === '') {
            return null;
        }
        // Valida formato vendor/package
        if (!preg_match('/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i', $package)) {
            return null;
        }
        return $package;
    }

    private function resolvePluginName(string $package): string
    {
        // Remove qualquer prefixo vendor/module- para obter o nome curto do plugin
        // Ex: sweflow/module-email -> email | vupi.us/module-email -> email | sweflow/email -> email
        if (preg_match('/^[^\/]+\/module-(.+)$/', $package, $m)) {
            return $m[1];
        }
        if (preg_match('/^[^\/]+\/(.+)$/', $package, $m)) {
            return $m[1];
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

        // 1. Tenta git clone
        $repoUrl = $this->resolvePackageRepo($package);

        if ($repoUrl && $this->gitAvailable()) {
            try {
                $this->gitCloneToModules($repoUrl, $targetDir);
                $this->installModuleDependencies($targetDir);
                $this->regenerateAutoload();
                $this->pluginManager->install($pluginName);
                $this->incrementDownload($package);
                return;
            } catch (\RuntimeException $e) {
                error_log("[Marketplace] git clone falhou: " . $e->getMessage() . " — tentando zip download");
            }
        }

        // 2. Fallback: download do zip do GitHub (não precisa de git instalado)
        if ($repoUrl && $this->tryZipInstall($repoUrl, $targetDir)) {
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
                // O vendor path é derivado do package name (ex: sweflow/module-email -> vendor/sweflow/module-email)
                $vendorPath = dirname(__DIR__, 3) . '/vendor/' . $package;
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

        $this->registerModuleAutoload($moduleDir, $meta);

        $toInstall = $this->resolvePackagesToInstall($meta['require'] ?? []);
        if (empty($toInstall)) {
            return;
        }

        if (!$this->composerAvailable()) {
            error_log("[Marketplace] Composer não disponível — dependências do módulo não instaladas.");
            return;
        }

        $this->runComposerRequire($toInstall);
    }

    /**
     * Filtra as dependências do composer.json do módulo, removendo as que já estão
     * instaladas no projeto ou que são restrições de plataforma (php, ext-*, lib-*).
     */
    private function resolvePackagesToInstall(array $require): array
    {
        $root          = dirname(__DIR__, 3);
        $installedPath = $root . '/vendor/composer/installed.json';
        $installedNames = [];

        if (is_file($installedPath)) {
            $installed      = json_decode((string) file_get_contents($installedPath), true) ?? [];
            $packages       = $installed['packages'] ?? $installed;
            $installedNames = array_column(is_array($packages) ? $packages : [], 'name');
        }

        $toInstall = [];
        foreach ($require as $dep => $version) {
            if ($dep === 'php' || str_starts_with($dep, 'ext-') || str_starts_with($dep, 'lib-')) {
                continue;
            }
            if (in_array($dep, $installedNames, true)) {
                continue;
            }
            $toInstall[] = $dep . ':' . $version;
        }

        return $toInstall;
    }

    /**
     * Executa composer require para uma lista de pacotes.
     */
    private function runComposerRequire(array $packages): void
    {
        $root     = dirname(__DIR__, 3);
        $composer = is_file($root . '/vendor/bin/composer') ? $root . '/vendor/bin/composer' : 'composer';

        $prevLimit = (int) ini_get('max_execution_time');
        if (ini_set('max_execution_time', '300') === false) {
            error_log('[Marketplace] Could not extend max_execution_time');
        }

        $proc = new Process(array_merge(
            [$composer, 'require'],
            $packages,
            ['--no-interaction', '--no-scripts', '--working-dir=' . $root]
        ));

        if ($prevLimit > 0) {
            ini_set('max_execution_time', (string) $prevLimit);
        }

        if (!$proc->run()) {
            error_log("[Marketplace] Falha ao instalar dependências: " . $proc->getOutput());
            throw new \RuntimeException(
                "Módulo instalado, mas falha ao instalar dependências: " . implode(', ', $packages) .
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
            $json = json_encode($projectComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $fp = fopen($composerPath, 'c+');
            if ($fp) {
                flock($fp, LOCK_EX);
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $json);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
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
            $context = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'Vupi.usAPI/1.0']]);
            $json = file_get_contents("https://packagist.org/packages/{$package}.json", false, $context);
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

    /**
     * Baixa o zip do branch main/master do GitHub e extrai para o targetDir.
     * Funciona sem git instalado — usa apenas file_get_contents + ZipArchive.
     */
    private function tryZipInstall(string $repoUrl, string $targetDir): bool
    {
        // Converte URL do repo para URL do zip: https://github.com/vendor/repo → .../archive/refs/heads/main.zip
        $repoUrl  = rtrim($repoUrl, '/');
        $branches = ['main', 'master'];

        foreach ($branches as $branch) {
            $zipUrl = $repoUrl . '/archive/refs/heads/' . $branch . '.zip';
            try {
                $ctx  = stream_context_create(['http' => [
                    'timeout'    => 30,
                    'user_agent' => 'Vupi.usAPI/1.0',
                    'follow_location' => 1,
                ]]);
                $data = @file_get_contents($zipUrl, false, $ctx);
                if ($data === false || strlen($data) < 100) continue;

                $tmp = sys_get_temp_dir() . '/vupi_module_' . bin2hex(random_bytes(6)) . '.zip';
                file_put_contents($tmp, $data);

                if (!class_exists('ZipArchive')) {
                    @unlink($tmp);
                    continue;
                }

                $zip = new \ZipArchive();
                if ($zip->open($tmp) !== true) {
                    @unlink($tmp);
                    continue;
                }

                $extractTo = sys_get_temp_dir() . '/vupi_extract_' . bin2hex(random_bytes(6));
                $zip->extractTo($extractTo);
                $zip->close();
                @unlink($tmp);

                // O zip do GitHub extrai para vendor-repo-branch/
                $entries = glob($extractTo . '/*', GLOB_ONLYDIR);
                if (empty($entries)) {
                    (new Process(['rm', '-rf', $extractTo]))->run();
                    continue;
                }

                // Move o diretório extraído para o targetDir
                rename($entries[0], $targetDir);
                (new Process(['rm', '-rf', $extractTo]))->run();
                return true;

            } catch (\Throwable $e) {
                error_log("[Marketplace] zip install falhou ({$branch}): " . $e->getMessage());
            }
        }

        return false;
    }

    private function gitCloneToModules(string $repoUrl, string $targetDir): void
    {
        if (is_dir($targetDir)) {
            return;
        }

        // Tenta HTTPS público primeiro, depois SSH como fallback
        $proc = new Process([
            'git', 'clone', '--depth=1',
            '--config', 'core.askPass=echo', // evita prompt de senha que trava o processo
            $repoUrl,
            $targetDir,
        ]);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $detail = mb_substr(trim($proc->getOutput()), 0, 300);
            // Limpa diretório parcialmente clonado
            if (is_dir($targetDir)) {
                (new Process(['rm', '-rf', $targetDir]))->run();
            }
            throw new \RuntimeException(
                "Falha ao clonar repositório: {$repoUrl}" .
                ($detail !== '' ? " — {$detail}" : '')
            );
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

        $prevLimit = (int) ini_get('max_execution_time');
        if (ini_set('max_execution_time', '300') === false) {
            error_log('[Marketplace] Could not extend max_execution_time');
        }

        $proc = new Process([
            $composer, 'require', $package,
            '--no-interaction',
            '--no-scripts',
            '--no-plugins',
            '--working-dir=' . $root,
        ]);
        $ok = $proc->run();

        if ($prevLimit > 0) {
            ini_set('max_execution_time', (string) $prevLimit);
        }

        if (!$ok) {
            error_log("[Marketplace] composer require {$package} failed: " . $proc->getOutput());
        }

        return $ok;
    }


    private function incrementDownload(string $package): void
    {
        // Usa o package name completo como chave (ex: sweflow/module-email)
        if (!str_contains($package, '/')) {
            return; // sem vendor, não normaliza
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

        $pkgFull  = $packageName ?: ('module-' . strtolower($moduleName));
        $changed  = false;

        $changed = $this->removeComposerRequire($json, $pkgFull) || $changed;
        $changed = $this->removeComposerAutoload($json, $moduleName) || $changed;
        $changed = $this->removeComposerRepositories($json, $moduleName, $pkgFull) || $changed;

        if ($changed) {
            $json = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $fp = fopen($composerPath, 'c+');
            if ($fp) {
                flock($fp, LOCK_EX);
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $json);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    private function removeComposerRequire(array &$json, string $pkgFull): bool
    {
        $changed = false;
        if (isset($json['require'][$pkgFull])) {
            unset($json['require'][$pkgFull]);
            $changed = true;
        }
        return $changed;
    }

    private function removeComposerAutoload(array &$json, string $moduleName): bool
    {
        $changed = false;
        if (!isset($json['autoload']['psr-4'])) {
            return false;
        }
        foreach ($json['autoload']['psr-4'] as $ns => $path) {
            if (str_contains(str_replace('\\', '/', $path), "src/Modules/{$moduleName}/")) {
                unset($json['autoload']['psr-4'][$ns]);
                $changed = true;
            }
        }
        return $changed;
    }

    private function removeComposerRepositories(array &$json, string $moduleName, string $pkgFull): bool
    {
        if (!isset($json['repositories']) || !is_array($json['repositories'])) {
            return false;
        }
        $simpleName = strtolower($moduleName);
        $pkgSimple  = strtolower(preg_replace('/^[^\/]+\/(?:module-)?/', '', $pkgFull));
        $before     = count($json['repositories']);
        $json['repositories'] = array_values(array_filter(
            $json['repositories'],
            function ($repo) use ($simpleName, $pkgSimple) {
                if (($repo['type'] ?? '') !== 'path') {
                    return true;
                }
                $url = strtolower(str_replace('\\', '/', $repo['url'] ?? ''));
                return !str_contains($url, $simpleName) && !str_contains($url, $pkgSimple);
            }
        ));
        if (empty($json['repositories'])) {
            unset($json['repositories']);
        }
        return count($json['repositories'] ?? []) !== $before;
    }
    
    private function removeModuleFromCapabilities(string $moduleName): void
    {
        $storageDir = dirname(__DIR__, 3) . '/storage';
        $registryFile = $storageDir . '/capabilities_registry.json';
        
        if (!is_file($registryFile) || !is_readable($registryFile)) {
            return;
        }
        
        $json = file_get_contents($registryFile);
        $map = ($json !== false) ? (json_decode($json, true) ?? []) : [];
        $changed = false;
        
        // O plugin name salvo no registry pode variar (ex: 'vupi.us-module-email', 'email', 'Email')
        // Vamos varrer e remover qualquer valor que pareça ser este módulo
        $candidates = [
            $moduleName,
            ucfirst($moduleName),
            strtolower($moduleName),
        ];
        // Adiciona variações com prefixo se o package name foi passado
        if (str_contains($moduleName, '/')) {
            $short = preg_replace('/^[^\/]+\/(?:module-)?/', '', $moduleName);
            $candidates[] = $short;
            $candidates[] = ucfirst($short);
            $candidates[] = strtolower($short);
        }
        
        foreach ($map as $cap => $activePlugin) {
            // Verifica se o activePlugin está na lista de candidatos
            if (in_array($activePlugin, $candidates)) {
                unset($map[$cap]);
                $changed = true;
            }
        }
        
        if ($changed) {
            $fp = fopen($registryFile, 'c+');
            if ($fp) {
                flock($fp, LOCK_EX);
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }


    private function scanLocalPlugins(string $query): array
    {
        $root = dirname(__DIR__, 3) . '/src/Modules';
        if (!is_dir($root)) return [];

        // Módulos nativos do projeto — nunca devem aparecer no marketplace
        $nativeModules = ['auth', 'usuario', 'documentacao'];

        $results = [];
        foreach (scandir($root) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (!is_dir($root . '/' . $dir)) continue;

            // Módulos nativos são parte do projeto, não são instaláveis pelo marketplace
            if (in_array(strtolower($dir), $nativeModules, true)) {
                continue;
            }

            $composerJson = $root . '/' . $dir . '/composer.json';

            // Sem composer.json = módulo interno sem identidade de pacote — não exibir
            if (!is_file($composerJson) || !is_readable($composerJson)) {
                continue;
            }

            $raw  = file_get_contents($composerJson);
            $meta = $raw !== false ? (json_decode($raw, true) ?: []) : [];

            // Sem nome de pacote explícito = não é um pacote publicável
            if (empty($meta['name'])) {
                continue;
            }

            $name = $meta['name'];
            $desc = $meta['description'] ?? 'Módulo do Sistema';

            if ($query !== '' &&
                stripos($name, $query) === false &&
                stripos($desc, $query) === false &&
                stripos($dir, $query) === false
            ) {
                continue;
            }

            $results[] = [
                'name'        => $name,
                'description' => $desc . ' (src/Modules)',
                'downloads'   => $this->getDownloadCount($name),
                'url'         => $meta['homepage'] ?? '',
                'repository'  => $meta['support']['source'] ?? '',
            ];
        }
        return $results;
    }

    private function getDownloadCount(string $moduleName): int
    {
        $stats = $this->loadStats();
        return $stats[$moduleName] ?? 0;
    }


    private function decrementDownload(string $package): void
    {
        if (!str_contains($package, '/')) {
            return;
        }
        $stats = $this->loadStats();
        if (isset($stats[$package]) && $stats[$package] > 0) {
            $stats[$package]--;
            $this->saveStats($stats);
        }
    }

    private function loadStats(): array
    {
        $file = dirname(__DIR__, 3) . '/storage/marketplace_stats.json';
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $json = file_get_contents($file);
        return $json !== false ? (json_decode($json, true) ?? []) : [];
    }

    private function saveStats(array $stats): void
    {
        $file = dirname(__DIR__, 3) . '/storage/marketplace_stats.json';
        $fp   = fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
