<?php
// Carrega variáveis do .env para $_ENV
if (file_exists(__DIR__ . '/.env')) {
	$lines = file(__DIR__ . '/.env');
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') continue;
		if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches)) {
			$key = $matches[1];
			$value = trim($matches[2], '"');
			$_ENV[$key] = $value;
		}
	}
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/api/status') {
	require_once __DIR__ . '/src/Controllers/StatusController.php';
	$controller = new \Src\Controllers\StatusController();
	$controller->index();
	exit;
}
// Autoload do Composer (se existir)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require __DIR__ . '/vendor/autoload.php';
}

// Carrega rotas dos módulos
require_once __DIR__ . '/src/Modules/Usuario/Routes/web.php';

// Serve arquivos estáticos da pasta public
$publicPath = __DIR__ . '/public' . $_SERVER['REQUEST_URI'];
if (php_sapi_name() === 'cli-server' && file_exists($publicPath) && !is_dir($publicPath)) {
	$mimeTypes = [
		'css' => 'text/css',
		'js' => 'application/javascript',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'svg' => 'image/svg+xml',
		'html' => 'text/html',
	];
	$ext = pathinfo($publicPath, PATHINFO_EXTENSION);
	if (isset($mimeTypes[$ext])) {
		header('Content-Type: ' . $mimeTypes[$ext]);
	}
	readfile($publicPath);
	exit;
}


$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/' || $uri === '/index.php') {
	require_once __DIR__ . '/src/Controllers/HomeController.php';
	$controller = new \Src\Controllers\HomeController();
	$controller->index();
	exit;
}


// Bloco de roteamento principal
$method = $_SERVER['REQUEST_METHOD'];
if (isset($routes) && is_array($routes)) {
	foreach ($routes as $route) {
		if ($route['method'] === $method) {
			$params = matchRoute($route['uri'], $uri);
			if ($params !== false) {
				// Executa middleware se existir
				if (!empty($route['middleware']) && class_exists($route['middleware'])) {
					$middleware = $route['middleware'];
					if (method_exists($middleware, 'handle')) {
						$middleware::handle();
					}
				}
				if (is_array($route['handler']) && count($route['handler']) === 2) {
					$controllerClass = $route['handler'][0];
					$methodName = $route['handler'][1];
					$controller = new $controllerClass();
					$controller->$methodName(...array_values($params));
				} else {
					call_user_func($route['handler']);
				}
				exit;
			}
		}
	}
}


// Se não encontrou rota, retorna página 404 estilizada
http_response_code(404);
$errorPage = __DIR__ . '/public/404.html';
if (file_exists($errorPage)) {
	readfile($errorPage);
} else {
	echo '<h1>404 - Página não encontrada</h1>';
}
exit;


function matchRoute($routeUri, $requestUri) {
	$routeParts = explode('/', trim($routeUri, '/'));
	$requestParts = explode('/', trim($requestUri, '/'));
	if (count($routeParts) !== count($requestParts)) return false;
	$params = [];
	foreach ($routeParts as $i => $part) {
		if (preg_match('/^{(.+)}$/', $part, $matches)) {
			$params[$matches[1]] = $requestParts[$i];
		} elseif ($part !== $requestParts[$i]) {
			return false;
		}
	}
	return $params;
}

foreach ($routes as $route) {
	if ($route['method'] === $method) {
		$params = matchRoute($route['uri'], $uri);
		if ($params !== false) {
			if (is_array($route['handler']) && count($route['handler']) === 2) {
				$controllerClass = $route['handler'][0];
				$methodName = $route['handler'][1];
				$controller = new $controllerClass();
				$controller->$methodName(...array_values($params));
			} else {
				call_user_func($route['handler']);
			}
			exit;
		}
	}
}

// Se não encontrou rota
http_response_code(404);
$modulesInfo = [];
$modulesDir = __DIR__ . '/src/Modules';
if (is_dir($modulesDir)) {
	$modules = array_filter(scandir($modulesDir), function($item) use ($modulesDir) {
		return is_dir($modulesDir . '/' . $item) && $item !== '.' && $item !== '..';
	});
	foreach ($modules as $module) {
		$routesFile = $modulesDir . "/{$module}/Routes/web.php";
		$routesList = [];
		if (file_exists($routesFile)) {
			// Tenta extrair as rotas do arquivo
			$fileContent = file_get_contents($routesFile);
			preg_match_all('/Route::(get|post|put|delete|patch)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $fileContent, $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				$method = strtoupper($match[1]);
				$uri = $match[2];
				$isPublic = strpos($fileContent, $match[0]) < strpos($fileContent, '// Rotas privadas') ? 'pública' : 'privada';
				$routesList[] = [
					'method' => $method,
					'uri' => $uri,
					'tipo' => $isPublic
				];
			}
		}
		$modulesInfo[] = [
			'modulo' => $module,
			'rotas' => $routesList
		];
	}
}
echo json_encode([
	'erro' => 'Rota não encontrada',
	'modulos' => $modulesInfo
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
