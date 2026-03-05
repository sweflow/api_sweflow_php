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