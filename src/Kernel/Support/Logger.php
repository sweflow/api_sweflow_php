<?php

namespace Src\Kernel\Support;

class Logger
{
    private RequestContext $context;
    private bool $debugEnabled;
    private bool $isTesting;
    private bool $isProduction;

    public function __construct(RequestContext $context)
    {
        $this->context      = $context;
        $env                = strtolower(trim($_ENV['APP_ENV'] ?? (string) getenv('APP_ENV') ?: 'local'));
        $debug              = strtolower(trim($_ENV['APP_DEBUG'] ?? (string) getenv('APP_DEBUG') ?: 'false'));
        $this->debugEnabled = in_array($debug, ['1', 'true', 'on', 'yes'], true);
        $this->isTesting    = $env === 'testing';
        $this->isProduction = $env === 'production';
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
        if ($this->debugEnabled) {
            $this->log('DEBUG', $message, $data);
        }
    }

    private function log(string $level, string $message, array $data): void
    {
        if ($this->isTesting) {
            return;
        }

        $payload = array_merge([
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'level'     => $level,
            'message'   => $message,
            'context'   => $this->context->toArray(),
        ], $data);

        if (!$this->isProduction) {
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

        file_put_contents('php://stderr', $logLine . PHP_EOL, FILE_APPEND);
    }
}