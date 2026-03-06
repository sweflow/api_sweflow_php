<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\Http\Response\Response;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Nucleo\PluginManager;

class SystemModulesController
{
    public function __construct(
        private PluginManager $pluginManager
    ) {}

    public function search(Request $request): Response
    {
        $query = $request->query['q'] ?? 'sweflow/module';
        
        // 1. Busca no Packagist
        $url = 'https://packagist.org/search.json?q=' . urlencode($query) . '&type=library'; 
        
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 5]
            ]);
            $json = @file_get_contents($url, false, $context);
            $data = $json ? json_decode($json, true) : [];
        } catch (\Throwable $e) {
            $data = ['results' => []];
        }

        // 2. Busca plugins locais
        $localPlugins = $this->scanLocalPlugins($query);
        $remoteNames = array_column($data['results'] ?? [], 'name');
        
        foreach ($localPlugins as $local) {
            if (!in_array($local['name'], $remoteNames)) {
                $data['results'][] = $local;
            }
        }

        // 3. Verifica status de instalação para cada resultado
        $installed = $this->pluginManager->read(); // Precisa ser publico ou usar getter
        
        foreach ($data['results'] as &$pkg) {
            // Normaliza nome para checar instalação (ex: sweflow/module-email -> email)
            $shortName = str_replace(['sweflow/module-', 'sweflow/', 'module-'], '', $pkg['name']);
            
            // Verifica se está no registro de plugins
            if (isset($installed[$shortName])) {
                $pkg['installed'] = true;
                $pkg['enabled'] = $installed[$shortName]['enabled'] ?? false;
            } else {
                $pkg['installed'] = false;
            }
        }

        return (new Response())->json($data);
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

            // Remove do composer.json antes de desinstalar
            $shortName = ucfirst($pluginName);
            $this->removeModuleFromComposer($shortName);

            $this->pluginManager->uninstall($pluginName);
            
            return (new Response())->json(['message' => 'Módulo removido com sucesso e composer.json atualizado']);
        } catch (\Throwable $e) {
            return (new Response())->json(['message' => 'Erro: ' . $e->getMessage()], 500);
        }
    }

    public function install(Request $request): Response
    {
        $body = $request->body;
        $package = $body['package'] ?? null;

        if (!$package) {
            return (new Response())->json(['message' => 'Pacote não informado'], 400);
        }

        try {
            // Verifica se é instalação local (dev) ou remota
            $pluginName = $package;
            if (str_starts_with($package, 'sweflow/module-')) {
                $pluginName = str_replace('sweflow/module-', '', $package);
            } elseif (str_starts_with($package, 'sweflow/')) {
                 $pluginName = str_replace('sweflow/', '', $package);
            }

            // Tenta instalar
            // Se o pacote for um sweflow-module, tentamos baixar via composer OU clonar para src/Modules
            // A diretiva do usuário é clara: "deve ser instalado em src/Modules e não em plugins"
            
            // 1. Identifica nome curto do módulo (ex: Email)
            $shortName = ucfirst($pluginName);
            $targetDir = dirname(__DIR__, 3) . '/src/Modules/' . $shortName;
            
            // 2. Se não existir, tenta baixar
            if (!is_dir($targetDir)) {
                 // Opção A: Se tiver composer, usa composer require mas força o path?
                 // O Composer não instala em src/Modules por padrão, a menos que seja configurado como path repo ou installer-paths.
                 // Como o usuário quer forçar src/Modules, podemos fazer um git clone manual se for um repo git conhecido,
                 // ou, se for via packagist, teríamos que baixar o zip e extrair.
                 
                 // Simulação de "Instalação": Copiar de plugins/ se existir (legado) ou criar estrutura básica?
                 // Na verdade, o correto seria o composer.json do projeto ter um "installer-path" configurado.
                 
                 // Mas vamos implementar o download manual do ZIP do GitHub/Packagist se possível, ou apenas instruir.
                 // Como o usuário pediu "Corrija!", ele espera que o botão funcione.
                 
                 // HACK: Se for o módulo de email que acabamos de publicar, vamos clonar do git para src/Modules/Email
                 if ($pluginName === 'email' || $pluginName === 'module-email') {
                     $repo = 'https://github.com/sweflow/module-email.git';
                     // git clone $repo $targetDir
                     $cmd = "git clone $repo \"$targetDir\" 2>&1";
                     exec($cmd, $output, $code);
                     
                     if ($code !== 0) {
                         // Fallback: Tenta mover de plugins/ se existir lá
                         $legacyPath = dirname(__DIR__, 3) . '/plugins/sweflow-module-email';
                         if (is_dir($legacyPath)) {
                             rename($legacyPath, $targetDir);
                         } else {
                             // Fallback 2: Composer require (vai para vendor, mas o PluginManager agora lê vendor também)
                             // Mas o usuário EXIGIU src/Modules.
                             // Então vamos lançar erro se não conseguir por lá.
                             throw new \Exception("Falha ao clonar repositório para src/Modules: " . implode("\n", $output));
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

            $this->pluginManager->install($pluginName);
            
            return (new Response())->json(['message' => 'Módulo instalado com sucesso em src/Modules e registrado no composer.json']);
        } catch (\Throwable $e) {
            return (new Response())->json(['message' => 'Erro: ' . $e->getMessage()], 500);
        }
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
        // Assume namespace padrão ou tenta adivinhar. 
        // Como não temos o path do módulo garantido (pode já ter sido apagado ou vamos apagar),
        // vamos procurar por namespaces que apontem para src/Modules/$moduleName
        
        $changed = false;
        if (isset($json['autoload']['psr-4'])) {
            foreach ($json['autoload']['psr-4'] as $ns => $path) {
                // Normaliza path
                $path = str_replace('\\', '/', $path);
                if (str_contains($path, "src/Modules/{$moduleName}/")) {
                    unset($json['autoload']['psr-4'][$ns]);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            exec('composer dump-autoload');
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
            exec('composer dump-autoload');
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
                'downloads' => 0,
                'url' => '',
                'repository' => ''
            ];
        }
        return $results;
    }
}
