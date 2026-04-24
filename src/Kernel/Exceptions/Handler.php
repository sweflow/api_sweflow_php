<?php

namespace Src\Kernel\Exceptions;

use Src\Kernel\Http\Response\Response;
use Throwable;

class Handler
{
    public function handle(Throwable $e): void
    {
        $this->report($e);
        $this->render($e)->send();
    }

    private function report(Throwable $e): void
    {
        // Sempre loga a mensagem + arquivo + linha
        error_log(sprintf(
            "[Vupi.us Error] %s: %s at %s:%d",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        // Sempre loga o trace completo — vai para o arquivo de log do PHP-FPM,
        // não para o usuário. Essencial para diagnosticar problemas em produção.
        error_log($e->getTraceAsString());

        // Se tem exceção anterior (causa raiz), loga também
        if ($e->getPrevious() instanceof \Throwable) {
            $prev = $e->getPrevious();
            error_log(sprintf(
                "[Vupi.us Error] Caused by %s: %s at %s:%d",
                get_class($prev),
                $prev->getMessage(),
                $prev->getFile(),
                $prev->getLine()
            ));
        }
    }

    private function render(Throwable $e): Response
    {
        $status  = $this->getStatusCode($e);
        $message = $e->getMessage();

        if ($this->isDbConnectionError($e) && !$this->wantsJson()) {
            return Response::html($this->dbErrorHtml(), 503);
        }

        // Erros de configuração de banco (RuntimeException com código 503)
        // são sempre exibidos — são problemas operacionais, não bugs de código.
        // A mensagem já é segura (gerada internamente, sem dados sensíveis).
        $isConfigError = $e instanceof \RuntimeException
            && $e->getCode() === 503
            && (
                str_contains($message, 'conexão secundária') ||
                str_contains($message, 'conexão principal') ||
                str_contains($message, 'DB2_') ||
                str_contains($message, 'connection.php') ||
                str_contains($message, 'não conseguiu conectar')
            );

        if ($isConfigError) {
            // Simplifica a mensagem para o usuário — remove instruções técnicas de .env
            $userMessage = explode("\n", $message)[0]; // só a primeira linha
            return Response::json([
                'status'  => 'error',
                'message' => $userMessage,
                'code'    => 'DB_CONFIG_ERROR',
            ], 503);
        }

        // Em produção, escondemos detalhes de erros 500
        if ($status === 500 && !$this->isDebug()) {
            $message = 'Erro interno no servidor.';
        }

        $body = [
            'status'  => 'error',
            'message' => $message,
        ];

        if ($this->isDebug()) {
            $body['exception'] = get_class($e);
            $body['file']      = $e->getFile();
            $body['line']      = $e->getLine();
            $body['trace']     = explode("\n", $e->getTraceAsString());

            // Contexto de conexão — essencial para diagnosticar problemas de banco
            try {
                $devResolver = \Src\Kernel\Database\DeveloperConnectionResolver::instance();
                $body['_debug'] = [
                    'user_id'           => $devResolver->getUserId(),
                    'is_native_request' => $devResolver->isNativeModuleRequest(),
                    'has_custom_pdo'    => $devResolver->resolve() !== null,
                    'request_uri'       => $_SERVER['REQUEST_URI'] ?? '/',
                    'auth_source'       => !empty($_SERVER['HTTP_AUTHORIZATION']) ? 'bearer' : (!empty($_COOKIE['auth_token']) ? 'cookie' : 'none'),
                ];
            } catch (\Throwable $debugErr) {
                $body['_debug'] = ['error' => $debugErr->getMessage()];
            }
        }

        return Response::json($body, $status);
    }

    private function wantsJson(): bool
    {
        return self::requestWantsJson($_SERVER['REQUEST_URI'] ?? '/');
    }

    /**
     * Determina se a requisição espera resposta JSON.
     * Centralizado aqui para evitar duplicação com index.php.
     */
    public static function requestWantsJson(string $requestUri = '/'): bool
    {
        $uri = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        if (strpos($uri, '/api/') === 0) {
            return true;
        }
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            return true;
        }
        $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        return $xrw === 'xmlhttprequest';
    }

    /**
     * Verifica se a exceção é um erro de conexão com banco de dados.
     * Centralizado aqui para evitar duplicação com index.php.
     */
    public static function isDbConnectionError(\Throwable $e): bool
    {
        $stack = [$e];
        if ($e->getPrevious() instanceof \Throwable) {
            $stack[] = $e->getPrevious();
        }
        foreach ($stack as $err) {
            if ($err instanceof \PDOException) {
                return true;
            }
            $msg = strtolower($err->getMessage());
            if (
                str_contains($msg, 'sqlstate[08006]') ||
                str_contains($msg, 'connection refused') ||
                str_contains($msg, 'não foi possível conectar ao banco')
            ) {
                return true;
            }
        }
        return false;
    }

    private function dbErrorHtml(): string
    {
        $driverEnv = strtolower($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql');
        $driverEnv = $driverEnv === 'postgresql' ? 'pgsql' : $driverEnv;
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $name = $_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? '';
        $port = $_ENV['DB_PORT'] ?? ($driverEnv === 'pgsql' ? '5432' : '3306');
        $appEnv = $_ENV['APP_ENV'] ?? 'local';

        $root = dirname(__DIR__, 3);
        $templatePath = $root . '/public/db-connection-error.html';
        $html = is_file($templatePath) ? (string) file_get_contents($templatePath) : '<!doctype html><meta charset="utf-8"><title>Banco indisponível</title><h1>Banco de dados indisponível</h1>';

        $replacements = [
            '{{driver}}' => htmlspecialchars((string) $driverEnv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{host}}' => htmlspecialchars((string) $host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{port}}' => htmlspecialchars((string) $port, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{database}}' => htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{app_env}}' => htmlspecialchars((string) $appEnv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
        return strtr($html, $replacements);
    }

    private function getStatusCode(Throwable $e): int
    {
        if ($e instanceof \DomainException) {
            $code = $e->getCode();
            return ($code >= 400 && $code < 600) ? $code : 400;
        }
        
        if ($e instanceof \InvalidArgumentException) {
            return 400;
        }

        return 500;
    }

    private function isDebug(): bool
    {
        // 1. Header secreto: permite debug em produção sem editar .env
        //    Uso: curl -H "X-Debug-Token: <valor de JWT_SECRET>" https://api.vupi.us/...
        $debugToken = $_SERVER['HTTP_X_DEBUG_TOKEN'] ?? '';
        if ($debugToken !== '') {
            $secret = $_ENV['JWT_SECRET'] ?? '';
            if ($secret !== '' && hash_equals($secret, $debugToken)) {
                return true;
            }
        }

        // 2. Query param: ?_debug=<JWT_SECRET> (útil para testar no navegador)
        $debugParam = $_GET['_debug'] ?? '';
        if ($debugParam !== '') {
            $secret = $_ENV['JWT_SECRET'] ?? '';
            if ($secret !== '' && hash_equals($secret, $debugParam)) {
                return true;
            }
        }

        // 3. Modo padrão: APP_ENV != production + APP_DEBUG=true
        $isProduction = ($_ENV['APP_ENV'] ?? 'local') === 'production';
        if ($isProduction) {
            return false;
        }
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }
}
