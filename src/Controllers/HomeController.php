<?php
namespace Src\Controllers;

class HomeController
{
    public function index()
    {
        require_once __DIR__ . '/../Configs/EnvConfig.php';
        \src\Configs\EnvConfig::carregar();
        $status = [
            'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
            'port' => $_SERVER['SERVER_PORT'] ?? '80',
            'env' => getenv('APP_ENV') ?: 'local',
            'debug' => (getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === true) ? 'true' : 'false',
        ];

        $modules = [];
        $modulesDir = __DIR__ . '/../Modules';
        if (is_dir($modulesDir)) {
            foreach (scandir($modulesDir) as $module) {
                if ($module === '.' || $module === '..') continue;
                $routes = [];
                $routesFile = $modulesDir . "/$module/Routes/web.php";
                if (file_exists($routesFile)) {
                    $fileContent = file_get_contents($routesFile);
                    preg_match_all('/Route::(get|post|put|delete|patch)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*\[(.*?)\](?:,\s*(.*?))?\s*\)/', $fileContent, $matches, PREG_SET_ORDER);
                    $added = [];
                    foreach ($matches as $match) {
                        $method = strtoupper($match[1]);
                        $uri = $match[2];
                        // O middleware pode estar no terceiro ou quarto parâmetro
                        $middlewares = '';
                        if (isset($match[4]) && !empty($match[4])) {
                            $middlewares = $match[4];
                        } elseif (isset($match[3]) && !empty($match[3])) {
                            $middlewares = $match[3];
                        }
                        // Ignora se a linha está comentada inteira (//)
                        if (preg_match('/^\s*\/\//', $match[0])) continue;
                        // Detecta se o middleware de proteção está presente e não comentado
                        $isPrivate = false;
                        if (
                            (
                                strpos($middlewares, 'AuthMiddleware::class') !== false ||
                                strpos($middlewares, 'RouteProtectionMiddleware::class') !== false
                            ) &&
                            strpos($middlewares, '/*') === false &&
                            strpos($middlewares, '*/') === false
                        ) {
                            $isPrivate = true;
                        }
                        $key = $method . $uri;
                        if (!isset($added[$key])) {
                            $routes[] = [
                                'method' => $method,
                                'uri' => $uri,
                                'tipo' => $isPrivate ? 'privada' : 'pública'
                            ];
                            $added[$key] = true;
                        }
                    }
                }
                $modules[] = [
                    'name' => $module,
                    'routes' => $routes
                ];
            }
        }
        $descricao = 'API modular PHP com detecção automática de módulos e rotas.';

        // Status do banco de dados
        $dbStatus = [
            'conectado' => false,
            'erro' => ''
        ];
        // Corrige nomes das variáveis conforme .env
        $dbType = $_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql';
        if ($dbType === 'postgresql') $dbType = 'pgsql';
        $dbHost = $_ENV['DB_HOST'] ?? '';
        $dbName = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $dbUser = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
        $dbPass = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
        $dbPort = $_ENV['DB_PORT'] ?? ($dbType === 'pgsql' ? '5432' : '3306');
        $canConnect = $dbHost && $dbName && $dbUser;
        if ($canConnect) {
            try {
                set_error_handler(function(){}); // ignora warnings temporariamente
                $options = [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 2 // timeout de 2 segundos (MySQL)
                ];
                if ($dbType === 'pgsql') {
                    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName";
                } else {
                    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName";
                }
                $pdo = @new \PDO($dsn, $dbUser, $dbPass, $options);
                @$pdo->query($dbType === 'pgsql' ? 'SELECT 1' : 'SELECT 1');
                $dbStatus['conectado'] = true;
                restore_error_handler();
            } catch (\Throwable $e) {
                restore_error_handler();
                $dbStatus['erro'] = $e->getMessage();
            }
        } else {
            $dbStatus['erro'] = 'Configuração do banco incompleta.';
        }

        include __DIR__ . '/../Views/index.php';
    }
}
