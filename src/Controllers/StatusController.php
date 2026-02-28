<?php
namespace Src\Controllers;

class StatusController
{
    public function index()
    {
        // Carrega variáveis do .env
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
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $status,
            'modules' => $modules
        ]);
    }
}
