<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Circuit Breaker — protege o backend de falhas em cascata.
 *
 * Estados:
 *   CLOSED  — operação normal, requisições passam
 *   OPEN    — muitas falhas detectadas, rejeita imediatamente sem tocar o backend
 *   HALF    — após cooldown, deixa passar uma requisição de teste
 *
 * Uso nas rotas que dependem de DB ou serviços externos:
 *   [CircuitBreakerMiddleware::class, ['service' => 'database', 'threshold' => 5, 'cooldown' => 30]]
 */
class CircuitBreakerMiddleware implements MiddlewareInterface
{
    private string $service;
    private int    $threshold;  // falhas consecutivas para abrir o circuito
    private int    $cooldown;   // segundos antes de tentar HALF
    private string $storageDir;

    public function __construct(
        string $service   = 'default',
        int    $threshold = 5,
        int    $cooldown  = 30
    ) {
        $this->service    = $service;
        $this->threshold  = $threshold;
        $this->cooldown   = $cooldown;
        $this->storageDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'circuit';
    }

    public function handle(Request $request, callable $next): Response
    {
        $state = $this->readState();

        // ── OPEN: circuito aberto — rejeita sem tocar o backend ──
        if ($state['status'] === 'OPEN') {
            $elapsed = time() - ($state['opened_at'] ?? 0);
            if ($elapsed < $this->cooldown) {
                $retryAfter = $this->cooldown - $elapsed;
                $this->log('circuit_breaker.rejected', $state);
                return Response::json(
                    ['error' => 'Serviço temporariamente indisponível. Tente novamente em ' . $retryAfter . ' segundos.'],
                    503
                )->withHeaders([
                    'Retry-After'   => (string) $retryAfter,
                    'X-CB-Status'   => 'OPEN',
                    'X-CB-Service'  => $this->service,
                ]);
            }
            // Cooldown expirou — transita para HALF
            $state['status'] = 'HALF';
            $this->writeState($state);
        }

        // ── CLOSED / HALF: deixa passar e monitora resultado ──
        try {
            $response = $next($request);
            $status   = $response->getStatusCode();

            // 5xx do backend = falha
            if ($status >= 500 && $status < 600) {
                $this->recordFailure($state);
            } else {
                $this->recordSuccess($state);
            }

            return $response;

        } catch (\Throwable $e) {
            $this->recordFailure($state);

            // Relança para o handler global tratar
            throw $e;
        }
    }

    // ── State machine ─────────────────────────────────────

    private function recordFailure(array $state): void
    {
        $state['failures']  = ($state['failures'] ?? 0) + 1;
        $state['successes'] = 0;

        if ($state['failures'] >= $this->threshold) {
            $state['status']    = 'OPEN';
            $state['opened_at'] = time();
            $this->log('circuit_breaker.opened', $state);
        }

        $this->writeState($state);
    }

    private function recordSuccess(array $state): void
    {
        if ($state['status'] === 'HALF') {
            // Uma requisição bem-sucedida em HALF fecha o circuito
            $state['status']   = 'CLOSED';
            $state['failures'] = 0;
            $this->log('circuit_breaker.closed', $state);
        } elseif ($state['status'] === 'CLOSED') {
            // Reseta contador de falhas em operação normal
            $state['failures']  = 0;
            $state['successes'] = ($state['successes'] ?? 0) + 1;
        }

        $this->writeState($state);
    }

    // ── Persistência ──────────────────────────────────────

    private function readState(): array
    {
        $file = $this->stateFile();
        if (!is_file($file)) {
            return ['status' => 'CLOSED', 'failures' => 0, 'successes' => 0, 'opened_at' => 0];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return ['status' => 'CLOSED', 'failures' => 0, 'successes' => 0, 'opened_at' => 0];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['status' => 'CLOSED', 'failures' => 0, 'successes' => 0, 'opened_at' => 0];
    }

    private function writeState(array $state): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }

        $file = $this->stateFile();
        $fp   = fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function stateFile(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'cb_' . preg_replace('/[^a-z0-9_]/', '_', $this->service) . '.json';
    }

    // ── Observabilidade ───────────────────────────────────

    private function log(string $event, array $state): void
    {
        $line = json_encode([
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'type'      => 'CIRCUIT_BREAKER',
            'event'     => $event,
            'service'   => $this->service,
            'status'    => $state['status'] ?? 'UNKNOWN',
            'failures'  => $state['failures'] ?? 0,
            'threshold' => $this->threshold,
            'cooldown'  => $this->cooldown,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }
}
