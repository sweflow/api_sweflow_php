<?php

namespace Src\Kernel\Exceptions;

use Src\Kernel\Http\Response\Response;
use Throwable;

class Handler
{
    public function handle(Throwable $e): void
    {
        $this->report($e);
        $this->render($e)->Enviar();
    }

    private function report(Throwable $e): void
    {
        // Aqui você pode integrar com Sentry, NewRelic, CloudWatch
        // Por enquanto, apenas log no arquivo padrão do PHP
        error_log("[Sweflow Error] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
        error_log($e->getTraceAsString());
    }

    private function render(Throwable $e): Response
    {
        $status = $this->getStatusCode($e);
        $message = $e->getMessage();

        if ($this->isDbConnectionError($e) && !$this->wantsJson()) {
            return Response::html($this->dbErrorHtml(), 503);
        }
        
        // Em produção, escondemos detalhes de erros 500
        if ($status === 500 && !$this->isDebug()) {
            $message = 'Erro interno no servidor.';
        }

        $body = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($this->isDebug()) {
            $body['exception'] = get_class($e);
            $body['file'] = $e->getFile();
            $body['line'] = $e->getLine();
            $body['trace'] = explode("\n", $e->getTraceAsString());
        }

        return Response::json($body, $status);
    }

    private function wantsJson(): bool
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
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

    private function isDbConnectionError(Throwable $e): bool
    {
        $stack = [$e];
        if ($e->getPrevious() instanceof Throwable) {
            $stack[] = $e->getPrevious();
        }

        foreach ($stack as $err) {
            if ($err instanceof \PDOException) {
                return true;
            }

            $msg = strtolower($err->getMessage());
            if (str_contains($msg, 'sqlstate[08006]') || str_contains($msg, 'connection refused') || str_contains($msg, 'não foi possível conectar ao banco')) {
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
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }
}
