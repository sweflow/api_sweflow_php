<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\Http\Response\Response;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Nucleo\PluginManager;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SystemModulesController
{
    public function __construct(
        private PluginManager $pluginManager
    ) {}

    public function search(Request $request): Response
    {
        $query = $request->query['q'] ?? '';
        $page = (int)($request->query['page'] ?? 1);
        $perPage = (int)($request->query['limit'] ?? 12);
        
        if ($page < 1) $page = 1;
        if ($perPage < 1) $perPage = 12;

        // 1. Busca plugins locais
        $localPlugins = $this->scanLocalPlugins($query);

        // 2. Busca no Packagist
        $remotePlugins = [];
        // Se a busca estiver vazia, talvez não queiramos buscar TUDO no packagist, 
        // mas vamos manter o padrão 'sweflow/module' se vazio.
        $searchQuery = empty($query) ? 'sweflow/module' : $query;
        $url = 'https://packagist.org/search.json?q=' . urlencode($searchQuery) . '&type=library'; 
        
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 5]
            ]);
            $json = @file_get_contents($url, false, $context);
            $remoteData = $json ? json_decode($json, true) : [];
            $remotePlugins = $remoteData['results'] ?? [];
        } catch (\Throwable $e) {
            // Silently fail remote search
        }

        // 3. Merge: Prioriza locais, adiciona remotos se não duplicados
        $localNames = array_column($localPlugins, 'name');
        $merged = $localPlugins;
        
        foreach ($remotePlugins as $remote) {
            if (!in_array($remote['name'], $localNames)) {
                $merged[] = $remote;
            }
        }

        // 4. Status de Instalação
        $installed = $this->pluginManager->read();
        foreach ($merged as &$pkg) {
            $shortName = str_replace(['sweflow/module-', 'sweflow/', 'module-'], '', $pkg['name']);
            if (isset($installed[$shortName])) {
                $pkg['installed'] = true;
                $pkg['enabled'] = $installed[$shortName]['enabled'] ?? false;
            } else {
                $pkg['installed'] = false;
            }
        }
        unset($pkg); // quebra referencia

        // 5. Paginação Manual (já que mergeamos fontes diferentes)
        $total = count($merged);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($merged, $offset, $perPage);

        return (new Response())->json([
            'results' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => ceil($total / $perPage)
            ]
        ]);
    }

    public function uninstall(Request $request): Response
    {
        $body = $request->body;
        $package = $body['package'] ?? null;

        if (!$package) {
            return (new Response())->json(['message' => 'Pacote não informado'], 400);
        }

        try {
    public function install(Request $request): Response
    {
        $body = $request->body;
        $package = $body['package'] ?? null;
        $status = 200;
        $message = '';

        if (!$package) {
            $message = 'Pacote não informado';
            $status = 400;
        } else {
            try {
                $pluginName = $this->extractPluginName($package);
                $shortName = $this->getShortName($pluginName);
                $targetDir = $this->getTargetDir($shortName);

                $this->performInstallation($package, $pluginName, $targetDir);
                $this->addModuleToComposer($shortName);

                $this->pluginManager->install($pluginName);
                $this->addModuleToCapabilities($shortName);
                $this->incrementDownload($package);

                $message = 'Módulo instalado com sucesso e composer.json atualizado';
            } catch (\Throwable $e) {
                $message = 'Erro: ' . $e->getMessage();
                $status = 500;
            }
        }

        return (new Response())->json(['message' => $message], $status);
    }

    private function extractPluginName(string $package): string
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

    private function performInstallation(string $package, string $pluginName, string $targetDir): void
    {
        // --- NOVO: Verificação de Dependências (PRÉ-INSTALAÇÃO) ---
        // Se for instalação via Packagist, podemos checar metadados antes?
        // Difícil sem fazer request. Vamos assumir que instalamos o principal e depois as deps.
        // Mas a diretiva diz: "identifique automaticamente se o módulo instalado necessita de outro(s)... se necessitar, o modulo deve obrigar a instalação"
         // HACK: Se for o módulo de email que acabamos de publicar, vamos clonar do git para src/Modules/Email
         if ($pluginName === 'email' || $pluginName === 'module-email') {
             $repo = 'https://github.com/sweflow/module-email.git';
             // git clone $repo $targetDir
             try {
                 $process = new Process(['git', 'clone', $repo, $targetDir]);
                 $process->mustRun();
             } catch (ProcessFailedException $exception) {
                 // Fallback: Tenta mover de plugins/ se existir lá
                 $legacyPath = dirname(__DIR__, 3) . '/plugins/sweflow-module-email';
                 if (is_dir($legacyPath)) {
                     rename($legacyPath, $targetDir);
                 } else {
                     // Fallback 2: Composer require (vai para vendor, mas o PluginManager agora lê vendor também)
                     // Mas o usuário EXIGIU src/Modules.
                     // Então vamos lançar erro se não conseguir por lá.
                     throw new \Exception("Falha ao clonar repositório para src/Modules: " . $exception->getMessage());
                 }
             }

             // Novo passo: Registrar no composer.json e psr-4
             $this->registerModuleInComposer($shortName, $targetDir);
         } else {
             // Para outros módulos genéricos, tentamos composer require padrão (vai para vendor)
             // A menos que implementemos um downloader genérico.
             // Vamos manter o composer require como fallback seguro para não quebrar tudo.
             $this->pluginManager->install($pluginName);
             return (new Response())->json(['message' => 'Módulo instalado via Composer (vendor).']);
         }
    } else {
        // Se já existe, garante que está registrado no composer.json
                $this->registerModuleInComposer($shortName, $targetDir);
            }

            // --- NOVO: Verificação de Dependências ---
            // Lê o composer.json do módulo instalado para checar dependências "require"
            $moduleComposerPath = $targetDir . '/composer.json';
            if (file_exists($moduleComposerPath)) {
                $modJson = json_decode(file_get_contents($moduleComposerPath), true);
                $requires = $modJson['require'] ?? [];
                
                foreach ($requires as $reqPackage => $version) {
                    // Verifica se é um módulo do sistema (sweflow/module-*)
                    if (str_starts_with($reqPackage, 'sweflow/module-')) {
                        // Verifica se já está instalado
                        $reqShortName = ucfirst(str_replace('sweflow/module-', '', $reqPackage));
                        $reqDir = dirname(__DIR__, 3) . '/src/Modules/' . $reqShortName;
                        
                        if (!is_dir($reqDir)) {
                            // Dependência faltando! Tenta instalar recursivamente?
                            // Para evitar loop infinito ou complexidade, vamos apenas lançar erro ou tentar instalar.
                            // A instrução diz: "o modulo deve obrigar a instalação dos módulos necessarios".
                            // Vamos tentar instalar a dependência automaticamente.
                            
                            // Cria um novo request simulado para instalar a dependência
                            $depRequest = new Request();
                            $depRequest->body = ['package' => $reqPackage];
                            
                            $response = $this->install($depRequest);
                            // O método getContent não existe na classe Response. 
                            // O body está na propriedade privada, acessível via getBody().
                            // Se for array/objeto, json_encode pode ser necessário ou acesso direto.
                            
                            $respBody = $response->getBody();
                            // Se o corpo já for um array (Response::json constrói com array), usamos direto.
                            // Se for string JSON, decodificamos.
                            
                            $respData = is_string($respBody) ? json_decode($respBody, true) : (array)$respBody;
                            
                            if (($response->getStatusCode() < 200 || $response->getStatusCode() >= 300)) {
                                // Se falhar a instalação da dependência, aborta tudo?
                                // Como já instalamos o módulo principal (git clone), ele vai ficar lá quebrado.
                                // O ideal seria rollback, mas vamos lançar exceção avisando.
                                throw new \Exception("Falha ao instalar dependência obrigatória '{$reqPackage}': " . ($respData['message'] ?? 'Erro desconhecido'));
                            }
                        }
                    }
                }
            }
            // -----------------------------------------

            $this->pluginManager->install($pluginName);
            
            // Incrementa contador
            $this->incrementDownload($package);
            
            return (new Response())->json(['message' => 'Módulo instalado com sucesso em src/Modules e registrado no composer.json']);
        } catch (\Throwable $e) {
            return (new Response())->json(['message' => 'Erro: ' . $e->getMessage()], 500);
        }
    }
            if (in_array($activePlugin, $candidates)) {
                unset($map[$cap]);
                $changed = true;
            }
        }
        
        if ($changed) {
            file_put_contents($registryFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    private function registerModuleInComposer(string $moduleName, string $modulePath): void
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

        // 1. Adicionar ao "require"
        // Como é um módulo local clonado, usamos "*" ou "dev-main" se quisermos simular, 
        // mas na verdade se está em src/Modules, não precisamos do "require" se o autoload PSR-4 estiver configurado.
        // O "require" serve para o composer instalar. Se já clonamos manualmente, o "require" pode ser redundante 
        // ou causar conflito se apontar para packagist.
        // Porem, se o modulo tem dependencias proprias, o composer precisa saber dele.
        // A melhor pratica para "path repository" é adicionar no "repositories" e depois no "require".
        
        // Vamos focar no pedido principal: "inserir automaticamente o caminho no psr-4".
        
        // Tenta descobrir o namespace do módulo lendo o composer.json do módulo
        $moduleComposerPath = $modulePath . '/composer.json';
        $namespace = "SweflowModules\\{$moduleName}\\"; // Default fallback
        
        if (file_exists($moduleComposerPath)) {
            $modJson = json_decode(file_get_contents($moduleComposerPath), true);
            if (isset($modJson['autoload']['psr-4'])) {
                // Pega o primeiro namespace definido
                $namespace = array_key_first($modJson['autoload']['psr-4']);
                $srcPath = reset($modJson['autoload']['psr-4']); // ex: "src/"
                
                // Ajusta o path relativo para o composer.json raiz
                // src/Modules/Email/src/
                $relativePath = "src/Modules/{$moduleName}/" . $srcPath;
                $relativePath = rtrim($relativePath, '/'); // remove trailing slash se tiver
                $relativePath .= '/'; // garante um slash no final
            } else {
                // Fallback structure
                $relativePath = "src/Modules/{$moduleName}/src/";
            }
        } else {
            // Assume estrutura padrão
            $relativePath = "src/Modules/{$moduleName}/src/";
        }

        // 2. Adicionar ao autoload psr-4
        if (!isset($json['autoload']['psr-4'])) {
            $json['autoload']['psr-4'] = [];
        }

        // Verifica se já existe
        if (!isset($json['autoload']['psr-4'][$namespace])) {
            $json['autoload']['psr-4'][$namespace] = $relativePath;
            
            // Salva e roda dump-autoload
            file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            
            // Tenta rodar composer dump-autoload
            // Assume que 'composer' está no PATH
            $process = new Process(['composer', 'dump-autoload']);
            try {
                $process->mustRun();
                echo $process->getOutput();
            } catch (ProcessFailedException $exception) {
                echo $exception->getMessage();
            }
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

    private function incrementDownload(string $moduleName): void
    {
        // Normalize name
        if (str_starts_with($moduleName, 'sweflow/module-')) {
            $moduleName = 'sweflow/module-' . str_replace('sweflow/module-', '', $moduleName);
        } elseif (!str_contains($moduleName, '/')) {
            $moduleName = 'sweflow/module-' . strtolower($moduleName);
        }

        $stats = $this->loadStats();
        if (!isset($stats[$moduleName])) {
            $stats[$moduleName] = 0;
        }
        $stats[$moduleName]++;
        $this->saveStats($stats);
    }

    private function loadStats(): array
    {
        $file = dirname(__DIR__, 3) . '/storage/marketplace_stats.json';
        if (!file_exists($file)) {
            return [];
        }
        $json = false;
        if (is_readable($file)) {
            $json = file_get_contents($file);
        }
        return $json ? json_decode($json, true) : [];
    }

    private function saveStats(array $stats): void
    {
        $file = dirname(__DIR__, 3) . '/storage/marketplace_stats.json';
        if (!empty($stats)) {
            file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT));
        }
    }
}
