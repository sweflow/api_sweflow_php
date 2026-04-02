<?php

namespace Src\Kernel\Support;

class Logger
{
    private RequestContext $context;

    public function __construct(RequestContext $context)
    {
        $this->context = $context;
    }

    public function info(string $message, array $data = []): void
    {
        $this->log('INFO', $message, $data);
    }

    public function error(string $message, array $data = []): void
    {
        $this->log('ERROR', $message, $data);
    }

    public function warning(string $message, array $data = []): void
    {
        $this->log('WARNING', $message, $data);
    }

    public function debug(string $message, array $data = []): void
    {
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $this->log('DEBUG', $message, $data);
        }
    }

    private function log(string $level, string $message, array $data): void
    {
        // Suprime logs em ambiente de teste
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($env === 'testing') {
            return;
        }

        $payload = array_merge([
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'level' => $level,
            'message' => $message,
            'context' => $this->context->toArray(),
        ], $data);

        // Em ambiente local, podemos formatar mais bonito.
        // Em produção, JSON estruturado é lei para ferramentas como Datadog/CloudWatch.
        if (($_ENV['APP_ENV'] ?? 'local') === 'local') {
            $logLine = sprintf(
                "[%s] %s: %s | ReqID: %s | %s",
                $payload['timestamp'],
                $level,
                $message,
                $payload['context']['request_id'] ?? 'N/A',
                json_encode($data)
            );
        } else {
            $logLine = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Escreve no stderr/stdout para que o container runtime (Docker/K8s) capture.
        // Evitamos arquivos locais em arquitetura cloud-native.
        file_put_contents('php://stderr', $logLine . PHP_EOL, FILE_APPEND);
    }
}