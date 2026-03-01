<?php
// Carrega variáveis do .env usando Dotenv
require __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}
// Rota para status do banco em tempo real
if (file_exists(__DIR__ . '/src/Modules/Usuario/Routes/web.php')) {
	require_once __DIR__ . '/src/Modules/Usuario/Routes/web.php';
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/api/status') {
	// Status do servidor
	$status = [
		'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
		'port' => $_SERVER['SERVER_PORT'] ?? '80',
		'env' => $_ENV['APP_ENV'] ?? 'local',
		'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'true' : 'false',
	];
	// Módulos detectados
	$modules = [];
	$modulesDir = __DIR__ . '/src/Modules';
	if (is_dir($modulesDir)) {
		foreach (scandir($modulesDir) as $module) {
			if ($module === '.' || $module === '..') continue;
			$routes = [];
			$routesFile = $modulesDir . "/$module/Routes/web.php";
			if (file_exists($routesFile)) {
				$fileContent = file_get_contents($routesFile);
				preg_match_all('/Route::(get|post|put|delete|patch)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*\[.*?\]\s*(?:,\s*([^\)]*))?\)/s', $fileContent, $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					$method = strtoupper($match[1]);
					$uri = $match[2];
					$middlewares = isset($match[3]) ? $match[3] : '';
					$isPrivate = false;
					if (
						strpos($middlewares, 'RouteProtectionMiddleware::class') !== false ||
						strpos($middlewares, 'RouteProtectionMiddleware') !== false
					) {
						$isPrivate = true;
					}
					$routes[] = [
						'method' => $method,
						'uri' => $uri,
						'tipo' => $isPrivate ? 'privada' : 'pública'
					];
				}
			}
			$modules[] = [
				'name' => $module,
				'routes' => $routes
			];
		}
	}
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'status' => $status,
		'modules' => $modules
	], JSON_UNESCAPED_UNICODE);
	exit;
}
if ($uri === '/api/db-status') {
	require __DIR__ . '/vendor/autoload.php';
	if (file_exists(__DIR__ . '/.env')) {
		$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
		$dotenv->load();
	}
	$dbType = $_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql';
	if ($dbType === 'postgresql') $dbType = 'pgsql';
	$dbHost = $_ENV['DB_HOST'] ?? '';
	$dbName = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
	$dbUser = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
	$dbPass = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
	$dbPort = $_ENV['DB_PORT'] ?? ($dbType === 'pgsql' ? '5432' : '3306');
	$canConnect = $dbHost && $dbName && $dbUser;
	$dbStatus = [
		'conectado' => false,
		'erro' => ''
	];
	if ($canConnect) {
		try {
			set_error_handler(function(){});
			$options = [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_TIMEOUT => 2
			];
			if ($dbType === 'pgsql') {
				$dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName";
			} else {
				$dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName";
			}
			$pdo = @new PDO($dsn, $dbUser, $dbPass, $options);
			@$pdo->query($dbType === 'pgsql' ? 'SELECT 1' : 'SELECT 1');
			$dbStatus['conectado'] = true;
			restore_error_handler();
		} catch (Throwable $e) {
			restore_error_handler();
			$dbStatus['erro'] = $e->getMessage();
		}
	} else {
		$dbStatus['erro'] = 'Configuração do banco incompleta.';
	}
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($dbStatus, JSON_UNESCAPED_UNICODE);
	exit;
}
// Autoload do Composer (se existir)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require __DIR__ . '/vendor/autoload.php';
}

// Carrega rotas dos módulos
$modulesDir = __DIR__ . '/src/Modules';
if (is_dir($modulesDir)) {
	foreach (scandir($modulesDir) as $module) {
		if ($module === '.' || $module === '..') continue;
		$routesFile = $modulesDir . "/$module/Routes/web.php";
		if (file_exists($routesFile)) {
			require_once $routesFile;
		}
	}
}


// Serve arquivos estáticos da pasta public, exceto para a rota principal
$publicPath = __DIR__ . '/public' . $_SERVER['REQUEST_URI'];
if (
	php_sapi_name() === 'cli-server' &&
	file_exists($publicPath) &&
	!is_dir($publicPath) &&
	$uri !== '/' && $uri !== '/index.php'
) {
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
				// Executa middlewares se existirem (suporta parâmetros)
				if (!empty($route['middleware'])) {
					if (is_array($route['middleware'])) {
						foreach ($route['middleware'] as $middlewareClass => $params) {
							if (is_string($middlewareClass) && class_exists($middlewareClass) && method_exists($middlewareClass, 'handle')) {
								$middlewareClass::handle($params);
							}
						}
					} elseif (is_string($route['middleware']) && class_exists($route['middleware']) && method_exists($route['middleware'], 'handle')) {
						$route['middleware']::handle();
					}
				}
				if (is_array($route['handler']) && count($route['handler']) === 2) {
					$controllerClass = $route['handler'][0];
					$methodName = $route['handler'][1];

					// Injeção de dependências profissional para UsuarioController
					if ($controllerClass === 'src\Modules\Usuario\Controllers\UsuarioController') {
						// Certifique-se que $pdo está disponível neste escopo
						if (!isset($pdo) || !$pdo instanceof PDO) {
							// Recria a conexão caso não exista
							$options = [
								PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
								PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
								PDO::ATTR_TIMEOUT => 2
							];
							$dbType = $_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql';
							if ($dbType === 'postgresql') $dbType = 'pgsql';
							$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
							$dbName = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
							$dbUser = $_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? '';
							$dbPass = $_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '';
							$dbPort = $_ENV['DB_PORT'] ?? ($dbType === 'pgsql' ? '5432' : '3306');
							$dsn = $dbType === 'pgsql'
								? "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName"
								: "mysql:host=$dbHost;port=$dbPort;dbname=$dbName";
							error_log('DEBUG: Tentando conectar ao banco com DSN: ' . $dsn . ' | Usuário: ' . $dbUser . ' | Host: ' . $dbHost . ' | Porta: ' . $dbPort);
							$pdo = new PDO($dsn, $dbUser, $dbPass, $options);
						}
						$usuarioRepository = new \src\Modules\Usuario\Repositories\UsuarioRepository($pdo);
						$usuarioService = new \src\Modules\Usuario\Services\UsuarioService($usuarioRepository);
						error_log('DEBUG: Antes de instanciar UsuarioController');
						$controller = new $controllerClass($usuarioService);
						error_log('DEBUG: UsuarioController instanciado');
						// Cria objeto Request
						$headers = getallheaders();
						$contentType = '';
						foreach ($headers as $k => $v) {
							if (strtolower($k) === 'content-type') {
								$contentType = strtolower($v);
								break;
							}
						}
						$rawBody = file_get_contents('php://input');
						$body = $_POST;
						if (str_contains($contentType, 'application/json')) {
							$json = json_decode($rawBody, true);
							if (is_array($json)) {
								$body = $json;
							}
						}
						$request = new \src\Http\Request\Request(
							$body,
							$_GET,
							$headers,
							$_SERVER['REQUEST_METHOD'] ?? null,
							$_SERVER['REQUEST_URI'] ?? null,
							$rawBody
						);
						// Adiciona parâmetros da rota (ex: uuid) ao objeto Request
						if (is_array($params)) {
							$request->params = $params;
						}
						error_log('DEBUG: Antes de chamar método do controller');
						// Passa Request como primeiro argumento
						$result = $controller->$methodName($request, ...(is_array($params) ? array_values($params) : []));
						if ($result instanceof \src\Http\Response\Response) {
							$result->Enviar();
						} elseif ($result) {
							echo $result;
						}
						error_log('DEBUG: Método do controller chamado');
					} else {
						$controller = new $controllerClass();
						$controller->$methodName(...(is_array($params) ? array_values($params) : []));
					}
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
