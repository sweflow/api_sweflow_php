<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\Storage\RateLimitStorageFactory;

/**
 * Circuit Breaker — protege o backend de falhas em cascata.
 *
 * Estados:
 *   CLOSED  — operação normal, requisições passam
 *   OPEN    — muitas falhas detectadas, rejeita imediatamente sem tocar o backend
 *   HALF    — após cooldown, deixa passar uma requisição de teste
 *
 * Storage:
 *   Redis disponível → estado compartilhado entre todos os nós (escala horizontal)
 *   Redis indisponível → arquivo local por nó (servidor único / dev)
 *
 * Uso nas rotas que dependem de DB ou serviços externos:
 *   [CircuitBreakerMiddleware::class, ['service' => 'database', 'threshold' => 5, 'cooldown' => 30]]
 */
class CircuitBreakerMiddleware implements MiddlewareInterface
{
    private string $service;
    private int    $threshold;
    private int    $cooldown;
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
            $state['status'] = 'HALF';
            $this->writeState($state);
        }

        // ── CLOSED / HALF: deixa passar e monitora resultado ──
        try {
            $response = $next($request);
            $status   = $response->getStatusCode();

            if ($status >= 500 && $status < 600) {
                $this->recordFailure($state);
            } else {
                $this->recordSuccess($state);
            }

            return $response;

        } catch (\Throwable $e) {
            $this->recordFailure($state);
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
            $state['status']   = 'CLOSED';
            $state['failures'] = 0;
            $this->log('circuit_breaker.closed', $state);
        } elseif ($state['status'] === 'CLOSED') {
            $state['failures']  = 0;
            $state['successes'] = ($state['successes'] ?? 0) + 1;
        }

        $this->writeState($state);
    }

    // ── Persistência (Redis ou File) ──────────────────────

    private function redisKey(): string
    {
        return 'cb:' . preg_replace('/[^a-z0-9_]/', '_', $this->service);
    }

    private function readState(): array
    {
        $default = ['status' => 'CLOSED', 'failures' => 0, 'successes' => 0, 'opened_at' => 0];

        // Tenta Redis primeiro — estado compartilhado entre nós
        $redis = $this->redis();
        if ($redis !== null) {
            $raw = $redis->get($this->redisKey());
            if ($raw === false || $raw === null) {
                return $default;
            }
            $data = json_decode((string) $raw, true);
            return is_array($data) ? $data : $default;
        }

        // Fallback: arquivo local
        $file = $this->stateFile();
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    private function writeState(array $state): void
    {
        $json = (string) json_encode($state);

        // Tenta Redis primeiro
        $redis = $this->redis();
        if ($redis !== null) {
            // TTL = cooldown * 3 — estado expira automaticamente se não houver atividade
            $redis->setex($this->redisKey(), $this->cooldown * 3, $json);
            return;
        }

        // Fallback: arquivo local com flock
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
        fwrite($fp, $json);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function redis(): ?\Redis
    {
        static $instance = null;
        static $checked  = false;

        if ($checked) {
            return $instance;
        }
        $checked = true;

        $host = trim($_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '');
        if ($host === '' || !extension_loaded('redis')) {
            return null;
        }

        try {
            $r = new \Redis();
            $r->connect(
                $host,
                (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379),
                2.0
            );
            $pass = trim($_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '');
            if ($pass !== '') {
                $r->auth($pass);
            }
            $r->select((int) ($_ENV['REDIS_DB'] ?? getenv('REDIS_DB') ?: 0));
            $prefix = trim($_ENV['REDIS_PREFIX'] ?? getenv('REDIS_PREFIX') ?: 'sweflow:');
            $r->setOption(\Redis::OPT_PREFIX, $prefix);
            $instance = $r;
        } catch (\Throwable) {
            $instance = null;
        }

        return $instance;
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
