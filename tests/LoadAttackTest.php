<?php
/**
 * Vupi.us API -- Load + Attack Combinado
 * Execucao: php tests/LoadAttackTest.php [BASE_URL]
 *
 * Valida:
 *   - Rate limit nao tem race condition sob 30 req simultaneas
 *   - Brute force e bloqueado antes de 15 tentativas
 *   - Servidor nao cai (500) durante carga mista com payloads maliciosos
 *   - ThreatScorer bloqueia UA de scanner rapidamente
 *   - Flood de registro e contido pelo rate limit
 *   - Servidor se recupera normalmente apos todos os ataques
 */
declare(strict_types=1);

$baseUrl = $argv[1] ?? (getenv("APP_URL") ?: "http://localhost:3005"); // NOSONAR
$baseUrl = rtrim($baseUrl, "/");
$passed = 0; $failed = 0; $results = [];

// ── Helpers ──────────────────────────────────────────────────────────────────

function loadReq(string $method, string $url, array $body = [], array $headers = [], int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => "Vupi.usLoadTest/1.0 (internal)",
        CURLOPT_HTTPHEADER     => array_merge(
            ["Content-Type: application/json", "Accept: application/json"],
            $headers
        ),
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $start = microtime(true);
    $raw   = curl_exec($ch);
    $ms    = (microtime(true) - $start) * 1000;
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err   = curl_error($ch);
    curl_close($ch);
    $rb = ($raw && $hs) ? substr($raw, $hs) : "";
    $d  = json_decode($rb, true);
    return [
        "status" => $code,
        "body"   => is_array($d) ? $d : ["_raw" => $rb],
        "ms"     => round($ms, 2),
        "error"  => $err ?: null,
    ];
}

/**
 * Dispara N requisicoes em paralelo via multi-curl.
 * Cada item de $requests: [method, url, body?, headers?, ua?]
 */
function parallelReqs(array $requests, int $timeout = 10): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $req) {
        $ch = curl_init($req["url"]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($req["method"] ?? "GET"),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => $req["ua"] ?? "Vupi.usLoadTest/1.0 (internal)",
            CURLOPT_HTTPHEADER     => array_merge(
                ["Content-Type: application/json", "Accept: application/json"],
                $req["headers"] ?? []
            ),
        ]);
        if (!empty($req["body"])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req["body"]));
        }
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $start = microtime(true);
    do {
        $s = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $s === CURLM_OK);
    $totalMs = (microtime(true) - $start) * 1000;

    $out = [];
    foreach ($handles as $i => $ch) {
        $raw  = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rb   = ($raw && $hs) ? substr($raw, $hs) : "";
        $d    = json_decode($rb, true);
        $out[$i] = ["status" => $code, "body" => is_array($d) ? $d : ["_raw" => $rb]];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return ["results" => $out, "total_ms" => round($totalMs, 2)];
}

function loadTest(string $name, callable $fn): void
{
    global $passed, $failed, $results;
    try {
        $r = $fn();
        if ($r === true || $r === null) {
            $passed++;
            $results[] = ["status" => "PASS", "name" => $name];
            echo "\033[32m  v\033[0m $name\n";
        } else {
            $failed++;
            $msg = is_string($r) ? $r : "falhou";
            $results[] = ["status" => "FAIL", "name" => $name, "reason" => $msg];
            echo "\033[31m  x\033[0m $name\n    \033[33m-> $msg\033[0m\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        $results[] = ["status" => "FAIL", "name" => $name, "reason" => $e->getMessage()];
        echo "\033[31m  x\033[0m $name\n    \033[33m-> " . $e->getMessage() . "\033[0m\n";
    }
}

function summarize(array $statuses): string
{
    $c = array_count_values($statuses);
    ksort($c);
    $p = [];
    foreach ($c as $code => $n) {
        $p[] = "HTTP $code x$n";
    }
    return implode(", ", $p);
}

// ── Conectividade ─────────────────────────────────────────────────────────────

echo "\n\033[1;33m[VUPI.US -- LOAD + ATTACK COMBINADO]\033[0m\n";
echo "Base URL: \033[1m$baseUrl\033[0m\n\n";

$ping = loadReq("GET", "$baseUrl/api/status");
if ($ping["status"] === 0) {
    echo "\033[31mERRO: Servidor nao acessivel em $baseUrl\033[0m\n";
    exit(1);
}
echo "\033[32mServidor acessivel (HTTP {$ping["status"]}, {$ping["ms"]}ms)\033[0m\n\n";

// Limpa storage antes dos testes
foreach ([dirname(__DIR__) . "/storage/ratelimit", dirname(__DIR__) . "/storage/threat"] as $dir) {
    if (is_dir($dir)) {
        foreach (glob($dir . "/*.json") ?: [] as $f) {
            @unlink($f);
        }
    }
}

// ── 1. Race Condition -- Rate Limit sob 30 req simultaneas ───────────────────
echo "\033[1m[1] Race Condition -- Rate Limit sob 30 req simultaneas\033[0m\n";

loadTest("Rate limit: nao passa mais que o limite sob concorrencia", function () use ($baseUrl) {
    $reqs = array_fill(0, 30, [
        "method" => "POST",
        "url"    => "$baseUrl/api/login",
        "body"   => ["login" => "race@test.com", "senha" => "errada"],
    ]);
    $b    = parallelReqs($reqs);
    $st   = array_column($b["results"], "status");
    $c429 = count(array_filter($st, fn($s) => $s === 429));
    $c500 = count(array_filter($st, fn($s) => $s === 500));
    echo "    " . summarize($st) . " | total={$b["total_ms"]}ms\n";
    if ($c500 > 0) {
        return "$c500 HTTP 500 sob carga -- servidor instavel";
    }
    if ($c429 === 0) {
        return "Nenhum 429 em 30 simultaneas -- possivel race condition no rate limit";
    }
    return null;
});

// ── 2. Brute Force -- bloqueio progressivo ───────────────────────────────────
echo "\n\033[1m[2] Brute Force -- bloqueio progressivo\033[0m\n";

loadTest("Brute force: IP bloqueado apos 15 tentativas", function () use ($baseUrl) {
    for ($i = 0; $i < 15; $i++) {
        $res = loadReq("POST", "$baseUrl/api/login", ["login" => "brute@test.com", "senha" => "errada_$i"]);
        if (in_array($res["status"], [429, 403])) {
            echo "    Bloqueado na tentativa $i (HTTP {$res["status"]})\n";
            return null;
        }
        if ($res["status"] === 500) {
            return "HTTP 500 na tentativa $i";
        }
    }
    return "IP nao bloqueado apos 15 tentativas de brute force";
});

loadTest("Brute force: resposta nao vaza stack trace", function () use ($baseUrl) {
    $res = loadReq("POST", "$baseUrl/api/login", ["login" => "brute2@test.com", "senha" => "errada"]);
    $b   = json_encode($res["body"]);
    if (str_contains($b, "Stack trace") || str_contains($b, "/var/www")) {
        return "Resposta de bloqueio vaza informacao interna";
    }
    return null;
});

// ── 3. Carga Mista -- Legitimas + Ataque Simultaneo ─────────────────────────
echo "\n\033[1m[3] Carga Mista -- Legitimas + Ataque Simultaneo\033[0m\n";

loadTest("Carga mista: servidor nao cai com 50 req maliciosas simultaneas", function () use ($baseUrl) {
    $reqs     = [];
    $payloads = ["' OR 1=1--", "<script>alert(1)</script>", str_repeat("A", 4096), "../../../etc/passwd"];
    for ($i = 0; $i < 10; $i++) {
        $reqs[] = ["method" => "GET", "url" => "$baseUrl/api/status"];
    }
    for ($i = 0; $i < 40; $i++) {
        $p = $payloads[$i % count($payloads)];
        $reqs[] = ["method" => "POST", "url" => "$baseUrl/api/login", "body" => ["login" => $p, "senha" => $p]];
    }
    shuffle($reqs);
    $b    = parallelReqs($reqs, 20);
    $st   = array_column($b["results"], "status");
    $c500 = count(array_filter($st, fn($s) => $s === 500));
    echo "    " . summarize($st) . " | total={$b["total_ms"]}ms\n";
    if ($c500 > 0) {
        return "$c500 HTTP 500 durante carga mista";
    }
    $after = loadReq("GET", "$baseUrl/api/status");
    if ($after["status"] !== 200) {
        return "Servidor nao responde apos ataque (HTTP {$after["status"]})";
    }
    return null;
});

// ── 4. ThreatScorer -- UA Malicioso ──────────────────────────────────────────
echo "\n\033[1m[4] ThreatScorer -- UA Malicioso\033[0m\n";

loadTest("ThreatScorer: sqlmap UA bloqueado rapidamente", function () use ($baseUrl) {
    $ua = "sqlmap/1.7.8#stable (https://sqlmap.org)";
    for ($i = 0; $i < 5; $i++) {
        $res = loadReq("GET", "$baseUrl/api/status", [], ["User-Agent: $ua"]);
        if (in_array($res["status"], [403, 429])) {
            echo "    Bloqueado na tentativa $i (HTTP {$res["status"]})\n";
            return null;
        }
    }
    return "UA de scanner (sqlmap) nao bloqueado em 5 tentativas";
});

loadTest("ThreatScorer: nikto UA bloqueado rapidamente", function () use ($baseUrl) {
    $ua = "Mozilla/5.0 (compatible; Nikto/2.1.6)";
    for ($i = 0; $i < 5; $i++) {
        $res = loadReq("GET", "$baseUrl/api/status", [], ["User-Agent: $ua"]);
        if (in_array($res["status"], [403, 429])) {
            echo "    Bloqueado na tentativa $i (HTTP {$res["status"]})\n";
            return null;
        }
    }
    return "UA de scanner (nikto) nao bloqueado em 5 tentativas";
});

// ── 5. Business Logic -- Flood de Registro ───────────────────────────────────
echo "\n\033[1m[5] Business Logic -- Flood de Registro\033[0m\n";

loadTest("Flood registro: 20 contas simultaneas sao bloqueadas por rate limit", function () use ($baseUrl) {
    $reqs = [];
    for ($i = 0; $i < 20; $i++) {
        $uid    = uniqid("flood_{$i}_");
        $reqs[] = [
            "method" => "POST",
            "url"    => "$baseUrl/api/registrar",
            "body"   => [
                "nome_completo" => "Flood $i",
                "username"      => $uid,
                "email"         => "$uid@test.invalid",
                "senha"         => "Senha@12345",
            ],
        ];
    }
    $b       = parallelReqs($reqs, 20);
    $st      = array_column($b["results"], "status");
    $created = count(array_filter($st, fn($s) => $s === 201));
    $blocked = count(array_filter($st, fn($s) => in_array($s, [429, 403])));
    $c500    = count(array_filter($st, fn($s) => $s === 500));
    echo "    Criados=$created Bloqueados=$blocked Erros500=$c500 | total={$b["total_ms"]}ms\n";
    if ($c500 > 0) {
        return "$c500 HTTP 500 durante flood de registro";
    }
    if ($created > 10 && $blocked === 0) {
        return "Flood criou $created contas sem bloqueio -- race condition no rate limit?";
    }
    return null;
});

// ── 6. Consistencia do Storage sob concorrencia ──────────────────────────────
echo "\n\033[1m[6] Consistencia do Storage -- sem drift\033[0m\n";

loadTest("Storage: 20 req paralelas ao mesmo endpoint sem erros 500", function () use ($baseUrl) {
    $reqs = array_fill(0, 20, ["method" => "GET", "url" => "$baseUrl/api/status"]);
    $b    = parallelReqs($reqs);
    $st   = array_column($b["results"], "status");
    $ok   = count(array_filter($st, fn($s) => $s === 200));
    $c500 = count(array_filter($st, fn($s) => $s === 500));
    $c0   = count(array_filter($st, fn($s) => $s === 0));
    echo "    OK=$ok Erros500=$c500 Timeout=$c0 | total={$b["total_ms"]}ms\n";
    if ($c500 > 0) {
        return "$c500 erros em 20 req paralelas -- possivel race condition no storage";
    }
    if ($c0 > 2) {
        return "$c0 timeouts em 20 req paralelas";
    }
    return null;
});

// ── 7. Recuperacao pos-ataque ─────────────────────────────────────────────────
echo "\n\033[1m[7] Recuperacao Pos-Ataque\033[0m\n";

loadTest("Servidor responde normalmente apos todos os ataques", function () use ($baseUrl) {
    sleep(1);
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $res = loadReq("GET", "$baseUrl/api/status");
        if ($res["status"] !== 200) {
            return "HTTP {$res["status"]} apos ataques (tentativa $i)";
        }
        $times[] = $res["ms"];
    }
    $avg = array_sum($times) / count($times);
    echo "    Latencia pos-ataque: avg={$avg}ms\n";
    if ($avg > 3000) {
        return "Latencia degradada: avg={$avg}ms (limite: 3000ms)";
    }
    return null;
});

loadTest("Endpoint de status retorna JSON valido apos ataques", function () use ($baseUrl) {
    $res = loadReq("GET", "$baseUrl/api/status");
    if ($res["status"] !== 200) {
        return "HTTP {$res["status"]} apos ataques";
    }
    if (empty($res["body"]) || isset($res["body"]["_raw"])) {
        return "Resposta nao e JSON valido apos ataques";
    }
    return null;
});

// ── Resumo ────────────────────────────────────────────────────────────────────
$total = $passed + $failed;
$pct   = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n\033[1;33m[RESULTADO FINAL]\033[0m\n";
printf("  Passou: %d  Falhou: %d  Taxa: %.1f%%\n\n", $passed, $failed, $pct);

if ($failed > 0) {
    foreach ($results as $r) {
        if ($r["status"] === "FAIL") {
            echo "  x {$r["name"]}\n    -> " . ($r["reason"] ?? "") . "\n";
        }
    }
    echo "\n";
}

exit($failed > 0 ? 1 : 0);
