<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Http\Response\Response;

/**
 * Controller: ModulesManagementController
 * 
 * Gerencia operações de módulos via dashboard
 */
class ModulesManagementController
{
    /**
     * Instala dependências de todos os módulos
     * 
     * POST /api/modules/install-dependencies
     */
    public function installDependencies(): Response
    {
        $root = dirname(__DIR__, 3);
        $modulesPath = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Modules';
        
        if (!is_dir($modulesPath)) {
            return Response::json([
                'success' => false,
                'error' => 'Diretório de módulos não encontrado',
            ], 500);
        }
        
        // Verifica se composer está disponível antes de processar
        if (!$this->commandExists('composer')) {
            return Response::json([
                'success' => false,
                'error' => 'Composer não está instalado no sistema',
            ], 500);
        }
        
        $modules = scandir($modulesPath);
        $installed = 0;
        $skipped = 0;
        $upToDate = 0;
        $failed = 0;
        $details = [];
        
        foreach ($modules as $module) {
            if ($module === '.' || $module === '..') {
                continue;
            }
            
            $moduleDir = $modulesPath . DIRECTORY_SEPARATOR . $module;
            
            if (!is_dir($moduleDir)) {
                continue;
            }
            
            $composerFile = $moduleDir . DIRECTORY_SEPARATOR . 'composer.json';
            
            if (!is_file($composerFile)) {
                $skipped++;
                $details[] = [
                    'module' => $module,
                    'status' => 'skipped',
                    'message' => 'Sem composer.json',
                ];
                continue;
            }
            
            // Verifica se as dependências já estão instaladas e atualizadas
            $vendorDir = $moduleDir . DIRECTORY_SEPARATOR . 'vendor';
            $composerLock = $moduleDir . DIRECTORY_SEPARATOR . 'composer.lock';
            
            if ($this->areDependenciesUpToDate($moduleDir, $composerFile, $composerLock, $vendorDir)) {
                $upToDate++;
                $details[] = [
                    'module' => $module,
                    'status' => 'up-to-date',
                    'message' => 'Dependências já instaladas',
                ];
                continue;
            }
            
            // Executa composer install no diretório do módulo
            $currentDir = getcwd();
            chdir($moduleDir);
            
            $output = shell_exec('composer install --no-interaction --prefer-dist 2>&1');
            
            chdir($currentDir);
            
            // Verifica se houve erro analisando a saída
            if ($output && (str_contains($output, 'Error') || str_contains($output, 'Fatal'))) {
                $failed++;
                $details[] = [
                    'module' => $module,
                    'status' => 'error',
                    'message' => 'Falha na instalação',
                    'output' => substr($output, 0, 200), // Limita output
                ];
            } else {
                $installed++;
                $details[] = [
                    'module' => $module,
                    'status' => 'success',
                    'message' => 'Dependências instaladas',
                ];
            }
        }
        
        return Response::json([
            'success' => true,
            'summary' => [
                'installed' => $installed,
                'up_to_date' => $upToDate,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => $installed + $upToDate + $skipped + $failed,
            ],
            'details' => $details,
            'all_up_to_date' => $installed === 0 && $failed === 0 && $upToDate > 0,
        ], 200);
    }
    
    /**
     * Verifica se as dependências de um módulo já estão instaladas e atualizadas
     * 
     * @param string $moduleDir Diretório do módulo
     * @param string $composerFile Caminho do composer.json
     * @param string $composerLock Caminho do composer.lock
     * @param string $vendorDir Caminho do diretório vendor
     * @return bool True se as dependências estão atualizadas
     */
    private function areDependenciesUpToDate(
        string $moduleDir,
        string $composerFile,
        string $composerLock,
        string $vendorDir
    ): bool {
        // Se não existe vendor/, precisa instalar
        if (!is_dir($vendorDir)) {
            return false;
        }
        
        // Se não existe composer.lock, precisa instalar
        if (!is_file($composerLock)) {
            return false;
        }
        
        // Verifica se composer.json foi modificado após composer.lock
        $jsonTime = filemtime($composerFile);
        $lockTime = filemtime($composerLock);
        
        if ($jsonTime > $lockTime) {
            // composer.json foi modificado, precisa atualizar
            return false;
        }
        
        // Lê o composer.json para verificar dependências
        $composerData = json_decode(file_get_contents($composerFile), true);
        
        if (!$composerData || empty($composerData['require'])) {
            // Sem dependências ou JSON inválido
            return true;
        }
        
        // Verifica se o autoload do vendor existe
        $autoloadFile = $vendorDir . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoloadFile)) {
            return false;
        }
        
        // Verifica se os pacotes principais estão instalados
        foreach ($composerData['require'] as $package => $version) {
            // Ignora php e extensões
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }
            
            // Converte nome do pacote para caminho (vendor/package)
            $packagePath = $vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $package);
            
            if (!is_dir($packagePath)) {
                // Pacote não está instalado
                return false;
            }
        }
        
        // Todas as verificações passaram, dependências estão atualizadas
        return true;
    }
    
    /**
     * Verifica se um comando existe no sistema
     */
    private function commandExists(string $command): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $test = shell_exec("where {$command} 2>nul");
            return !empty($test);
        }
        $test = shell_exec("command -v {$command} 2>/dev/null");
        return !empty($test);
    }
}
