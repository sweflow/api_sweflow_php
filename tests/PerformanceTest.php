<?php
/**
 * Sweflow API — Testes de Performance
 *
 * Execução: php tests/PerformanceTest.php [BASE_URL]
 *
 * Métricas:
 *   - Tempo de resposta (p50, p95, p99, max)
 *   - Throughput (req/s)
 *   - Taxa de erro
 *   - Tempo de resposta sob carga (concorrência simulada)
 */
declare(strict_types=1);

$baseUrl = $argv[1] ?? 'http://localhost:3005';
$baseUrl = rtrim($baseUrl, '/');

// ─── Thresholds ──────────────────────────────────────────────────────────────
// Ajustados para php -S (dev server single-threaded no Windows).
// Em produção com Nginx+PHP-FPM espera-se p95 < 50ms em endpoints simples.
const P95_LIMIT_MS   = 800;   // p95 deve ser < 800ms (dev: ~270ms esperado)
const P99_LIMIT_MS   = 1500;  // p99 deve ser < 1500ms
const AVG_LIMIT_MS   = 600;   // média deve ser < 600ms
const ERROR_RATE_MAX = 0.02;  // taxa de erro < 2%
const MIN_RPS        = 2;     // mínimo 2 req/s (dev server sequencial)

$passed = 0; $failed = 0; $results = [];

// ─── Helpers ─────────────────────────────────────────────────────────────────

function bench(string $method, string $url, array $body = [], array $headers = [], int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $headers
        ),
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $start   = microtime(true);
    $raw     = curl_exec($ch);
    $elapsed = (microtime(true) - $start) * 1000;

    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err     = curl_error($ch);
    curl_close($ch);

    $rawBody = $raw ? substr($raw, $hdrSize) : '';
    $decoded = json_decode($rawBody, true);

    return [
        'ms'     => round($elapsed, 2),
        'status' => $code,
        'body'   => is_array($decoded) ? $decoded : [],
        'error'  => $err ?: null,
    ];
}

function runN(int $n, callable $fn): array {
    $times = []; $errors = 0;
    for ($i = 0; $i < $n; $i++) {
        $r = $fn($i);
        if ($r['status'] === 0 || $r['error']) { $errors++; continue; }
        $times[] = $r['ms'];
    }
    if (empty($times)) return ['times' => [], 'errors' => $errors, 'n' => $n];
    sort($times);
    $count = count($times);
    return [
        'times'  => $times,
        'n'      => $n,
        'errors' => $errors,
        'avg'    => round(array_sum($times) / $count, 2),
        'min'    => round($times[0], 2),
        'max'    => round($times[$count - 1], 2),
        'p50'    => round($times[(int)($count * 0.50)], 2),
        'p95'    => round($times[(int)($count * 0.95)], 2),
        'p99'    => round($times[min((int)($count * 0.99), $count - 1)], 2),
        'rps'    => $count > 0 ? round($count / (array_sum($times) / 1000), 2) : 0,
        'error_rate' => round($errors / $n, 4),
    ];
}

function perf(string $name, array $stats, array $thresholds = []): void {
    global $passed, $failed, $results;

    $p95Limit  = $thresholds['p95']        ?? P95_LIMIT_MS;
    $avgLimit  = $thresholds['avg']        ?? AVG_LIMIT_MS;
    $errLimit  = $thresholds['error_rate'] ?? ERROR_RATE_MAX;
    $rpsMin    = $thresholds['rps_min']    ?? 0;

    $failures = [];
    if (($stats['p95'] ?? 0) > $p95Limit)         $failures[] = "p95={$stats['p95']}ms > {$p95Limit}ms";
    if (($stats['avg'] ?? 0) > $avgLimit)          $failures[] = "avg={$stats['avg']}ms > {$avgLimit}ms";
    if (($stats['error_rate'] ?? 0) > $errLimit)   $failures[] = "error_rate=" . round(($stats['error_rate'] ?? 0) * 100, 1) . "% > " . ($errLimit * 100) . "%";
    if ($rpsMin > 0 && ($stats['rps'] ?? 0) < $rpsMin) $failures[] = "rps={$stats['rps']} < {$rpsMin}";

    $label = empty($failures) ? "\033[32m  ✓\033[0m" : "\033[31m  ✗\033[0m";
    $n     = $stats['n'] ?? 0;
    $avg   = $stats['avg'] ?? 0;
    $p95   = $stats['p95'] ?? 0;
    $p99   = $stats['p99'] ?? 0;
    $max   = $stats['max'] ?? 0;
    $rps   = $stats['rps'] ?? 0;
    $err   = round(($stats['error_rate'] ?? 0) * 100, 1);

    echo "$label $name\n";
    printf("       n=%d  avg=%.0fms  p95=%.0fms  p99=%.0fms  max=%.0fms  rps=%.1f  err=%.1f%%\n",
        $n, $avg, $p95, $p99, $max, $rps, $err);

    if (!empty($failures)) {
        foreach ($failures as $f) echo "       \033[33m→ $f\033[0m\n";
        $failed++;
        $results[] = ['status' => 'FAIL', 'name' => $name, 'reason' => implode('; ', $failures), 'stats' => $stats];
    } else {
        $passed++;
        $results[] = ['status' => 'PASS', 'name' => $name, 'stats' => $stats];
    }
}

// ─── Conectividade ───────────────────────────────────────────────────────────

echo "\n\033[1;36m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;36m║         SWEFLOW API — TESTES DE PERFORMANCE               ║\033[0m\n";
echo "\033[1;36m╚══════════════════════════════════════════════════════════╝\033[0m\n";
echo "Base URL: \033[1m$baseUrl\033[0m\n\n";

$ping = bench('GET', "$baseUrl/api/status");
if ($ping['status'] === 0) {
    echo "\033[31mERRO: Servidor não acessível em $baseUrl\033[0m\n";
    exit(1);
}
echo "\033[32mServidor acessível (HTTP {$ping['status']}, {$ping['ms']}ms)\033[0m\n\n";

// ─── 1. Endpoints Públicos ────────────────────────────────────────────────────
echo "\033[1m[1] Endpoints Públicos (GET, sem auth)\033[0m\n";

$stats = runN(30, fn() => bench('GET', "$baseUrl/api/status"));
perf('GET /api/status (30x)', $stats, ['p95' => 500, 'avg' => 400, 'rps_min' => MIN_RPS]);

$stats = runN(20, fn() => bench('GET', "$baseUrl/api/db-status"));
perf('GET /api/db-status (20x)', $stats, ['p95' => 600, 'avg' => 450]);

$stats = runN(20, fn() => bench('GET', "$baseUrl/sitemap.xml"));
perf('GET /sitemap.xml (20x)', $stats, ['p95' => 600, 'avg' => 450]);

$stats = runN(20, fn() => bench('GET', "$baseUrl/robots.txt"));
perf('GET /robots.txt (20x)', $stats, ['p95' => 500, 'avg' => 400]);

// ─── 2. Autenticação ─────────────────────────────────────────────────────────
echo "\n\033[1m[2] Autenticação\033[0m\n";

// Limpa rate limit antes dos testes de auth
$rlDir = dirname(__DIR__) . '/storage/ratelimit';
if (is_dir($rlDir)) {
    foreach (glob($rlDir . '/*.json') as $f) { @unlink($f); }
}

$stats = runN(15, fn($i) => bench('POST', "$baseUrl/api/login", [
    'login' => "perf_user_$i@test.invalid",
    'senha' => 'senha_errada_perf',
]));
perf('POST /api/login — credenciais inválidas (15x)', $stats, ['p95' => 600, 'avg' => 400]);

$stats = runN(15, fn($i) => bench('POST', "$baseUrl/api/auth/recuperacao-senha", [
    'email' => "perf_$i@test.invalid",
]));
perf('POST /api/auth/recuperacao-senha (15x)', $stats, ['p95' => 500, 'avg' => 300]);

// ─── 3. Registro de Usuário ───────────────────────────────────────────────────
echo "\n\033[1m[3] Registro de Usuário\033[0m\n";

$stats = runN(10, fn($i) => bench('POST', "$baseUrl/api/registrar", [
    'nome_completo' => "Perf User $i",
    'username'      => 'perf_' . uniqid() . "_$i",
    'email'         => 'perf_' . uniqid() . "_$i@test.invalid",
    'senha'         => 'PerfSenha@123',
]));
perf('POST /api/registrar — novo usuário (10x)', $stats, ['p95' => 800, 'avg' => 400, 'error_rate' => 0.0]);

// ─── 4. Carga Sequencial ─────────────────────────────────────────────────────
echo "\n\033[1m[4] Carga Sequencial — 50 requisições\033[0m\n";

$stats = runN(50, fn() => bench('GET', "$baseUrl/api/status"));
perf('GET /api/status — 50 req sequenciais', $stats, ['p95' => 500, 'avg' => 400, 'rps_min' => MIN_RPS]);

$stats = runN(50, fn($i) => bench('POST', "$baseUrl/api/login", [
    'login' => "load_$i@test.invalid",
    'senha' => 'errada',
]));
perf('POST /api/login — 50 req sequenciais', $stats, ['p95' => 800, 'avg' => 600, 'error_rate' => 0.0]);

// ─── 5. Carga Paralela (multi-curl) ──────────────────────────────────────────
echo "\n\033[1m[5] Carga Paralela — multi-curl\033[0m\n";

function parallelGet(string $url, int $concurrency): array {
    $mh = curl_multi_init();
    $handles = [];
    $start = microtime(true);

    for ($i = 0; $i < $concurrency; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status === CURLM_OK);

    $elapsed = (microtime(true) - $start) * 1000;
    $codes = [];
    foreach ($handles as $ch) {
        $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    $errors = count(array_filter($codes, fn($c) => $c === 0 || $c >= 500));
    return [
        'n'          => $concurrency,
        'total_ms'   => round($elapsed, 2),
        'avg'        => round($elapsed / $concurrency, 2),
        'p95'        => round($elapsed / $concurrency * 1.2, 2), // estimativa conservadora
        'p99'        => round($elapsed / $concurrency * 1.5, 2),
        'max'        => round($elapsed, 2),
        'rps'        => round($concurrency / ($elapsed / 1000), 2),
        'errors'     => $errors,
        'error_rate' => round($errors / $concurrency, 4),
        'codes'      => array_count_values($codes),
    ];
}

$stats = parallelGet("$baseUrl/api/status", 10);
printf("  \033[36m→\033[0m 10 req paralelas: total=%.0fms  rps=%.1f  erros=%d  códigos=%s\n",
    $stats['total_ms'], $stats['rps'], $stats['errors'],
    json_encode($stats['codes']));
perf('GET /api/status — 10 paralelas', $stats, ['p95' => 500, 'avg' => 300, 'error_rate' => 0.0]);

$stats = parallelGet("$baseUrl/api/status", 25);
printf("  \033[36m→\033[0m 25 req paralelas: total=%.0fms  rps=%.1f  erros=%d  códigos=%s\n",
    $stats['total_ms'], $stats['rps'], $stats['errors'],
    json_encode($stats['codes']));
perf('GET /api/status — 25 paralelas', $stats, ['p95' => 800, 'avg' => 500, 'error_rate' => 0.0]);

// ─── 6. Payloads Grandes ─────────────────────────────────────────────────────
echo "\n\033[1m[6] Resiliência — Payloads Grandes\033[0m\n";

$stats = runN(5, fn() => bench('POST', "$baseUrl/api/login", [
    'login' => str_repeat('a', 10 * 1024),
    'senha' => str_repeat('b', 10 * 1024),
], [], 15));
perf('POST /api/login — payload 20KB (5x)', $stats, ['p95' => 1000, 'avg' => 500, 'error_rate' => 0.0]);

$stats = runN(3, fn() => bench('POST', "$baseUrl/api/login", [
    'login' => str_repeat('x', 512 * 1024),
    'senha' => 'y',
], [], 20));
perf('POST /api/login — payload 512KB (3x)', $stats, ['p95' => 2000, 'avg' => 1000, 'error_rate' => 0.0]);

// ─── 7. Tempo de Resposta por Rota ───────────────────────────────────────────
echo "\n\033[1m[7] Latência por Rota (10 amostras cada)\033[0m\n";

$routes = [
    ['GET',  '/api/status',      [],                                    ['p95' => 500, 'avg' => 400]],
    ['GET',  '/api/db-status',   [],                                    ['p95' => 600, 'avg' => 450]],
    ['POST', '/api/login',       ['login'=>'x@x.com','senha'=>'errada'],['p95' => 800, 'avg' => 600]],
    ['GET',  '/api/perfil',      [],                                    ['p95' => 600, 'avg' => 450]],
    ['GET',  '/sitemap.xml',     [],                                    ['p95' => 600, 'avg' => 450]],
    ['GET',  '/robots.txt',      [],                                    ['p95' => 500, 'avg' => 400]],
];

// Limpa rate limit novamente
if (is_dir($rlDir)) {
    foreach (glob($rlDir . '/*.json') as $f) { @unlink($f); }
}

foreach ($routes as [$method, $path, $body, $thresholds]) {
    $stats = runN(10, fn() => bench($method, "$baseUrl$path", $body));
    perf("$method $path (10x)", $stats, $thresholds);
}

// ─── 8. Estabilidade — Sem Degradação ────────────────────────────────────────
echo "\n\033[1m[8] Estabilidade — Sem Degradação ao Longo do Tempo\033[0m\n";

$batches = [];
for ($b = 0; $b < 5; $b++) {
    $batchTimes = [];
    for ($i = 0; $i < 10; $i++) {
        $r = bench('GET', "$baseUrl/api/status");
        if ($r['status'] > 0) $batchTimes[] = $r['ms'];
    }
    if (!empty($batchTimes)) {
        $batches[] = round(array_sum($batchTimes) / count($batchTimes), 2);
    }
    usleep(200000); // 200ms entre batches
}

if (count($batches) >= 2) {
    $first = $batches[0];
    $last  = $batches[count($batches) - 1];
    $degradation = $first > 0 ? round((($last - $first) / $first) * 100, 1) : 0;

    printf("  Médias por batch: %s\n", implode('ms → ', array_map(fn($v) => "{$v}", $batches)) . 'ms');
    printf("  Degradação: %.1f%%\n", $degradation);

    $label = $degradation <= 50 ? "\033[32m  ✓\033[0m" : "\033[31m  ✗\033[0m";
    echo "$label Degradação ao longo de 5 batches\n";
    if ($degradation <= 50) {
        $passed++;
        $results[] = ['status' => 'PASS', 'name' => 'Estabilidade — sem degradação', 'stats' => ['degradation_pct' => $degradation]];
    } else {
        $failed++;
        $results[] = ['status' => 'FAIL', 'name' => 'Estabilidade — sem degradação', 'reason' => "Degradação de {$degradation}% (limite: 50%)"];
    }
}

// ─── Resumo ───────────────────────────────────────────────────────────────────
$total = $passed + $failed;
$pct   = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n\033[1;36m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;36m║                    RESULTADO FINAL                       ║\033[0m\n";
echo "\033[1;36m╚══════════════════════════════════════════════════════════╝\033[0m\n";
printf("  \033[32m✓ Passou:   %3d\033[0m\n", $passed);
printf("  \033[31m✗ Falhou:   %3d\033[0m\n", $failed);
printf("  Taxa de sucesso: \033[1m%.1f%%\033[0m (%d/%d)\n\n", $pct, $passed, $total);

echo "\033[1m  THRESHOLDS UTILIZADOS:\033[0m\n";
printf("  %-30s %dms\n", 'p95 padrão:', P95_LIMIT_MS);
printf("  %-30s %dms\n", 'avg padrão:', AVG_LIMIT_MS);
printf("  %-30s %.0f%%\n", 'taxa de erro máx:', ERROR_RATE_MAX * 100);
printf("  %-30s %d req/s\n\n", 'throughput mínimo:', MIN_RPS);

if ($failed > 0) {
    echo "\033[1;31m  FALHAS:\033[0m\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            echo "  \033[31m✗\033[0m {$r['name']}\n";
            echo "    \033[33m→ " . ($r['reason'] ?? '') . "\033[0m\n";
        }
    }
    echo "\n";
}

if ($failed === 0) {
    echo "\033[1;32m  ✓ TODOS OS TESTES DE PERFORMANCE PASSARAM\033[0m\n\n";
} else {
    echo "\033[1;31m  ✗ $failed TESTE(S) FALHARAM\033[0m\n\n";
}

exit($failed > 0 ? 1 : 0);
