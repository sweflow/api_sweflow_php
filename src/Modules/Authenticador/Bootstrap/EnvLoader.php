<?php

namespace Src\Modules\Authenticador\Bootstrap;

use Dotenv\Dotenv;

/**
 * EnvLoader: Carregador de variáveis de ambiente do módulo
 * 
 * Carrega o .env específico do módulo Authenticador em adição ao .env raiz
 * Permite que o módulo tenha suas próprias configurações isoladas
 */
class EnvLoader
{
    private static bool $loaded = false;
    
    /**
     * Carrega o .env do módulo Authenticador
     * 
     * @return void
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        
        $modulePath = __DIR__ . '/..';
        $envFile = $modulePath . '/.env';
        
        if (!file_exists($envFile)) {
            // Se não existe .env, tenta carregar do .env.example como fallback
            $envExampleFile = $modulePath . '/.env.example';
            if (file_exists($envExampleFile)) {
                // Em desenvolvimento, copia .env.example para .env
                if (($_ENV['APP_ENV'] ?? 'local') === 'local') {
                    copy($envExampleFile, $envFile);
                }
            }
        }
        
        if (file_exists($envFile)) {
            $dotenv = Dotenv::createImmutable($modulePath);
            $dotenv->load();
        }
        
        self::$loaded = true;
    }
    
    /**
     * Obtém uma variável de ambiente do módulo
     * 
     * @param string $key Nome da variável
     * @param mixed $default Valor padrão se não existir
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::load();
        return $_ENV[$key] ?? $default;
    }
    
    /**
     * Verifica se uma variável de ambiente existe
     * 
     * @param string $key Nome da variável
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::load();
        return isset($_ENV[$key]);
    }
}
