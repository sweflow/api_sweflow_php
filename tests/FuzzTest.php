<?php
/**
 * Sweflow API — Fuzzing de API (substitui OWASP ZAP para CI)
 *
 * Execução: php tests/FuzzTest.php [BASE_URL]
 *
 * Cobre:
 *   - Fuzzing de campos com payloads maliciosos (XSS, SQLi, path traversal,
 *     command injection, format strings, unicode, null bytes, oversized)
 *   - Fuzzing de headers HTTP (Content-Type, Accept, X-Forwarded-For, etc.)
 *   - Fuzzing de métodos HTTP não esperados (verb tampering)
 *   - Fuzzing de parâmetros de query string
 *   - Detecção de vazamento de informação em respostas de erro
 *   - Detecção de comportamento diferenciado (timing oracle, error oracle)
 *
 * Critério de falha:
 *   - HTTP 500 em qualquer payload (nunca deve vazar stack trace)
 *   - Resposta contém stack trace, caminho absoluto, versão de lib
 *   - Comportamento diferenciado entre usuário existente/inexistente (timing > 200ms)
 *   - Servidor para de responder (timeout)
 */
declare(strict_types=1);

$baseUrl = $argv[1] ?? (getenv('APP_URL') ?: 'http://localhost:3005'); // NOSONAR
$baseUrl = rtrim($baseUrl, '/');

$passed = 0; $failed = 0; $skipped = 0; $results = [];

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fuzzReq(string $method, string $url, mixed $body = null, array $headers = [], int $timeout = 10): array
{
    $ch = curl_init($url);
    $defaultHeaders = ['Accept: application/json'];

    if ($body !== null) {
        if (is_array($body)) {
            $defaultHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } else {
            // raw body — para fuzzing de Content-Type
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => 'SweflowFuzzTest/1.0 (internal)',
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
    ]);

    $start   = microtime(true);
    $raw     = curl_exec($ch);
    $elapsed = (microtime(true) - $start) * 1000;
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err) {
        return ['status' => 0, 'body' => '', 'ms' => $elapsed, 'error' => $err];
    }

    $rawBody = substr($raw, $hdrSize);
    return ['status' => $code, 'body' => $rawBody, 'ms' => round($elapsed, 2), 'error' => null];
}

function fuzzTest(string $name, callable $fn): void
{
    global $passed, $failed, $results;
    try {
        $r = $fn();
        if ($r === true || $r === null) {
            $passed++;
            $results[] = ['status' => 'PASS', 'name' => $name];
            echo "\033[32m  ✓\033[0m $name\n";
        } else {
            $failed++;
            $msg = is_string($r) ? $r : 'falhou';
            $results[] = ['status' => 'FAIL', 'name' => $name, 'reason' => $msg];
            echo "\033[31m  ✗\033[0m $name\n    \033[33m→ $msg\033[0m\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        $results[] = ['status' => 'FAIL', 'name' => $name, 'reason' => $e->getMessage()];
        echo "\033[31m  ✗\033[0m $name\n    \033[33m→ " . $e->getMessage() . "\033[0m\n";
    }
}

/**
 * Verifica se a resposta vaza informação sensível.
 * Retorna string com o problema encontrado, ou null se ok.
 */
function checkLeak(string $body, int $status, string $payload): ?string
{
    if ($status === 500) {
        return "HTTP 500 com payload: " . substr($payload, 0, 80);
    }
    if ($status === 0) {
        return "Servidor não respondeu (timeout) com payload: " . substr($payload, 0, 80);
    }

    $lower = strtolower($body);

    // Stack trace
    if (preg_match('/#\d+ \/|Stack trace:|Trace:|\.php\(\d+\)/', $body)) {
        return "Stack trace vazado com payload: " . substr($payload, 0, 80);
    }
    // Caminhos absolutos
    if (preg_match('#(/var/www|/home/\w|/usr/local|C:\\\\Users|C:\\\\inetpub)#i', $body)) {
        return "Caminho absoluto vazado com payload: " . substr($payload, 0, 80);
    }
    // Versões de libs
    if (preg_match('/PHP\/[\d.]+|Laravel\/[\d.]+|Symfony\/[\d.]+/i', $body)) {
        return "Versão de framework vazada com payload: " . substr($payload, 0, 80);
    }
    // SQL errors
    if (preg_match('/SQLSTATE|syntax error|mysql_fetch|pg_query|ORA-\d+/i', $body)) {
        return "Erro SQL vazado com payload: " . substr($payload, 0, 80);
    }

    return null;
}

// ─── Payloads de Fuzzing ─────────────────────────────────────────────────────

/** Payloads SQLi — testa blind spots mesmo com prepared statements */
function sqlPayloads(): array
{
    return [
        // Clássicos
        "' OR '1'='1",
        "' OR 1=1--",
        "'; DROP TABLE users;--",
        "' UNION SELECT NULL,NULL,NULL--",
        "admin'--",
        "' OR 'x'='x",
        // Blind boolean
        "' AND 1=1--",
        "' AND 1=2--",
        "' AND SLEEP(0)--",          // SLEEP(0) — não bloqueia, mas testa parsing
        "'; WAITFOR DELAY '0:0:0'--", // MSSQL
        // Second-order
        "admin'/*",
        "' OR ''='",
        // Encoding bypass
        "%27 OR %271%27=%271",
        "\\' OR 1=1--",
        // NoSQL injection
        '{"$gt": ""}',
        '{"$where": "1==1"}',
        // JSON injection
        '"}; DROP TABLE users;{"',
    ];
}

/** Payloads XSS */
function xssPayloads(): array
{
    return [
        '<script>alert(1)</script>',
        '"><script>alert(1)</script>',
        "';alert(1)//",
        '<img src=x onerror=alert(1)>',
        '<svg onload=alert(1)>',
        'javascript:alert(1)',
        '"><img src=x onerror=alert(document.cookie)>',
        '<iframe src="javascript:alert(1)">',
        // Encoded
        '&lt;script&gt;alert(1)&lt;/script&gt;',
        '\u003cscript\u003ealert(1)\u003c/script\u003e',
        // DOM-based
        '#<script>alert(1)</script>',
    ];
}

/** Payloads de path traversal e command injection */
function injectionPayloads(): array
{
    return [
        // Path traversal
        '../../../etc/passwd',
        '..\\..\\..\\windows\\system32\\drivers\\etc\\hosts',
        '%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd',
        '....//....//....//etc/passwd',
        // Command injection
        '; ls -la',
        '| cat /etc/passwd',
        '`id`',
        '$(id)',
        '; ping -c 1 127.0.0.1',
        // LDAP injection
        '*)(uid=*))(|(uid=*',
        // XML/XXE
        '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>',
        // Template injection
        '{{7*7}}',
        '${7*7}',
        '<%= 7*7 %>',
    ];
}

/** Payloads de formato e encoding */
function formatPayloads(): array
{
    return [
        // Format strings
        '%s%s%s%s%s',
        '%d%d%d%d%d',
        '%x%x%x%x%x',
        '%n%n%n%n%n',
        // Null bytes
        "test\x00admin",
        "test%00admin",
        // Unicode
        "\u{202E}reversed",  // Right-to-left override
        str_repeat("\u{FEFF}", 10), // BOM
        "\u{0000}",
        // Oversized
        str_repeat('A', 65536),
        str_repeat('🔥', 1000),
        // Whitespace
        "\t\n\r\0",
        "   ",
        "",
        // Special JSON
        'null',
        'true',
        'false',
        '[]',
        '{}',
        '-1',
        '9999999999999999999',
        '1.7976931348623158E+308', // PHP_FLOAT_MAX
        'NaN',
        'Infinity',
    ];
}

// ─── Conectividade ───────────────────────────────────────────────────────────

echo "\n\033[1;35m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;35m║         SWEFLOW API — FUZZING DE SEGURANÇA               ║\033[0m\n";
echo "\033[1;35m╚══════════════════════════════════════════════════════════╝\033[0m\n";
echo "Base URL: \033[1m$baseUrl\033[0m\n\n";

$ping = fuzzReq('GET', "$baseUrl/api/status");
if ($ping['status'] === 0) {
    echo "\033[31mERRO: Servidor não acessível em $baseUrl\033[0m\n";
    exit(1);
}
echo "\033[32mServidor acessível (HTTP {$ping['status']})\033[0m\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 1. SQLi — campo login
// ═══════════════════════════════════════════════════════════════════════════
echo "\033[1m[1] SQL Injection — campo login\033[0m\n";

foreach (sqlPayloads() as $payload) {
    $label = substr($payload, 0, 50);
    fuzzTest("SQLi login: $label", function () use ($baseUrl, $payload) {
        $res = fuzzReq('POST', "$baseUrl/api/login", ['login' => $payload, 'senha' => 'qualquer']);
        return checkLeak($res['body'], $res['status'], $payload);
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. SQLi — campo senha
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[2] SQL Injection — campo senha\033[0m\n";

$sqlSubset = array_slice(sqlPayloads(), 0, 8); // subset para não duplicar muito
foreach ($sqlSubset as $payload) {
    $label = substr($payload, 0, 50);
    fuzzTest("SQLi senha: $label", function () use ($baseUrl, $payload) {
        $res = fuzzReq('POST', "$baseUrl/api/login", ['login' => 'test@test.com', 'senha' => $payload]);
        return checkLeak($res['body'], $res['status'], $payload);
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. XSS — campos de registro
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[3] XSS — campos de registro\033[0m\n";

foreach (xssPayloads() as $payload) {
    $label = substr($payload, 0, 50);
    fuzzTest("XSS nome_completo: $label", function () use ($baseUrl, $payload) {
        $res = fuzzReq('POST', "$baseUrl/api/registrar", [
            'nome_completo' => $payload,
            'username'      => 'xss_' . uniqid(),
            'email'         => 'xss_' . uniqid() . '@test.invalid',
            'senha'         => 'Senha@12345',
        ]);
        // XSS em API JSON: verifica que o payload não é refletido sem escape
        if ($res['status'] === 500) {
            return "HTTP 500 com XSS payload: " . substr($payload, 0, 80);
        }
        // Se retornou o payload na resposta, verifica se está escapado
        if (str_contains($res['body'], '<script>') || str_contains($res['body'], 'onerror=')) {
            return "XSS refletido sem escape: " . substr($payload, 0, 80);
        }
        return null;
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. Injection — campos de registro
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[4] Path Traversal / Command Injection\033[0m\n";

foreach (injectionPayloads() as $payload) {
    $label = substr($payload, 0, 50);
    fuzzTest("Injection: $label", function () use ($baseUrl, $payload) {
        $res = fuzzReq('POST', "$baseUrl/api/registrar", [
            'nome_completo' => $payload,
            'username'      => 'inj_' . uniqid(),
            'email'         => 'inj_' . uniqid() . '@test.invalid',
            'senha'         => 'Senha@12345',
        ]);
        $leak = checkLeak($res['body'], $res['status'], $payload);
        if ($leak) return $leak;
        // Verifica conteúdo de /etc/passwd
        if (str_contains($res['body'], 'root:') || str_contains($res['body'], '/bin/bash')) {
            return "Conteúdo de /etc/passwd vazado — CRÍTICO";
        }
        return null;
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. Format strings e encoding
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[5] Format Strings / Encoding / Edge Cases\033[0m\n";

foreach (formatPayloads() as $payload) {
    $label = substr(json_encode($payload), 0, 50);
    fuzzTest("Format: $label", function () use ($baseUrl, $payload) {
        $res = fuzzReq('POST', "$baseUrl/api/login", ['login' => $payload, 'senha' => $payload]);
        return checkLeak($res['body'], $res['status'], (string) $payload);
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. Fuzzing de Content-Type
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[6] Fuzzing de Content-Type\033[0m\n";

$contentTypes = [
    'text/plain',
    'text/html',
    'application/xml',
    'application/x-www-form-urlencoded',
    'multipart/form-data',
    'application/octet-stream',
    'application/javascript',
    'text/csv',
    '',
    'application/json; charset=utf-8; boundary=something',
    str_repeat('x', 512), // oversized content-type
];

foreach ($contentTypes as $ct) {
    $label = substr($ct ?: '(vazio)', 0, 50);
    fuzzTest("Content-Type: $label", function () use ($baseUrl, $ct) {
        $headers = $ct !== '' ? ["Content-Type: $ct"] : [];
        $res = fuzzReq('POST', "$baseUrl/api/login",
            '{"login":"test@test.com","senha":"errada"}',
            $headers
        );
        if ($res['status'] === 500) {
            return "HTTP 500 com Content-Type: " . substr($ct, 0, 80);
        }
        return null;
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 7. HTTP Verb Tampering
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[7] HTTP Verb Tampering\033[0m\n";

$verbRoutes = [
    ['PATCH',   '/api/login'],
    ['PUT',     '/api/login'],
    ['DELETE',  '/api/login'],
    ['OPTIONS', '/api/login'],
    ['TRACE',   '/api/login'],
    ['CONNECT', '/api/login'],
    ['HEAD',    '/api/login'],
    ['PATCH',   '/api/registrar'],
    ['DELETE',  '/api/registrar'],
    ['GET',     '/api/registrar'],
];

foreach ($verbRoutes as [$method, $path]) {
    fuzzTest("Verb $method $path não retorna 500", function () use ($baseUrl, $method, $path) {
        $res = fuzzReq($method, "$baseUrl$path");
        if ($res['status'] === 500) {
            return "HTTP 500 com $method $path";
        }
        // TRACE deve ser bloqueado (retorna 405 ou 404, nunca 200 com echo do body)
        if ($method === 'TRACE' && $res['status'] === 200) {
            if (str_contains($res['body'], 'TRACE')) {
                return "TRACE habilitado — vaza headers internos";
            }
        }
        return null;
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 8. Fuzzing de headers HTTP
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[8] Fuzzing de Headers HTTP\033[0m\n";

$headerFuzz = [
    ["X-Forwarded-For: 127.0.0.1, 10.0.0.1, " . str_repeat('1.2.3.4, ', 50)],
    ["X-Forwarded-For: ' OR 1=1--"],
    ["X-Real-IP: ../../etc/passwd"],
    ["X-Real-IP: 999.999.999.999"],
    ["Host: evil.com"],
    ["Host: localhost:3005\r\nX-Injected: header"],
    ["Accept: " . str_repeat('application/json, ', 200)],
    ["Accept-Language: " . str_repeat("en-US,", 500)],
    ["Cookie: session=" . str_repeat('A', 4096)],
    ["Authorization: Bearer " . str_repeat('A', 4096)],
    ["X-Request-ID: ' OR 1=1--"],
    ["Referer: javascript:alert(1)"],
    ["User-Agent: () { :; }; /bin/bash -c 'id'"], // Shellshock
    ["User-Agent: " . str_repeat('A', 8192)],
];

foreach ($headerFuzz as $headers) {
    $label = substr($headers[0], 0, 60);
    fuzzTest("Header: $label", function () use ($baseUrl, $headers) {
        $res = fuzzReq('GET', "$baseUrl/api/status", null, $headers);
        if ($res['status'] === 500) {
            return "HTTP 500 com header: " . substr($headers[0], 0, 80);
        }
        if ($res['status'] === 0) {
            return "Servidor não respondeu com header: " . substr($headers[0], 0, 80);
        }
        return null;
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 9. Fuzzing de query string
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[9] Fuzzing de Query String\033[0m\n";

$queryFuzz = [
    "?id=' OR 1=1--",
    "?id=1 UNION SELECT NULL--",
    "?callback=<script>alert(1)</script>",
    "?redirect=javascript:alert(1)",
    "?redirect=//evil.com",
    "?redirect=http://evil.com",
    "?file=../../../etc/passwd",
    "?page=" . str_repeat('A', 2048),
    "?" . str_repeat('a=b&', 500),
    "?__proto__[admin]=true",
    "?constructor[prototype][admin]=true",
];

foreach ($queryFuzz as $qs) {
    $label = substr($qs, 0, 60);
    fuzzTest("QueryString: $label", function () use ($baseUrl, $qs) {
        $res = fuzzReq('GET', "$baseUrl/api/status$qs");
        if ($res['status'] === 500) {
            return "HTTP 500 com query: " . substr($qs, 0, 80);
        }
        $leak = checkLeak($res['body'], $res['status'], $qs);
        return $leak;
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 10. Timing Oracle — detecção de enumeração de usuários
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[10] Timing Oracle — Enumeração de Usuários\033[0m\n";

fuzzTest('Timing: login usuário inexistente vs senha errada < 200ms diferença', function () use ($baseUrl) {
    $samples = 5;
    $timesExistente  = [];
    $timesInexistente = [];

    for ($i = 0; $i < $samples; $i++) {
        $r1 = fuzzReq('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada_' . $i]);
        $timesExistente[] = $r1['ms'];

        $r2 = fuzzReq('POST', "$baseUrl/api/login", ['login' => 'ghost_' . uniqid() . '@x.invalid', 'senha' => 'errada']);
        $timesInexistente[] = $r2['ms'];
    }

    $avgExistente   = array_sum($timesExistente)   / $samples;
    $avgInexistente = array_sum($timesInexistente) / $samples;
    $diff = abs($avgExistente - $avgInexistente);

    if ($diff > 200) {
        return sprintf(
            "Diferença de timing %.0fms pode indicar enumeração de usuários (existente=%.0fms, inexistente=%.0fms)",
            $diff, $avgExistente, $avgInexistente
        );
    }
    return null;
});

fuzzTest('Timing: recuperação de senha — e-mail existente vs inexistente < 300ms diferença', function () use ($baseUrl) {
    $samples = 3;
    $timesExistente   = [];
    $timesInexistente = [];

    for ($i = 0; $i < $samples; $i++) {
        $r1 = fuzzReq('POST', "$baseUrl/api/auth/recuperacao-senha", ['email' => 'admin@example.com']);
        $timesExistente[] = $r1['ms'];

        $r2 = fuzzReq('POST', "$baseUrl/api/auth/recuperacao-senha", ['email' => 'ghost_' . uniqid() . '@x.invalid']);
        $timesInexistente[] = $r2['ms'];
    }

    $avgExistente   = array_sum($timesExistente)   / $samples;
    $avgInexistente = array_sum($timesInexistente) / $samples;
    $diff = abs($avgExistente - $avgInexistente);

    if ($diff > 300) {
        return sprintf(
            "Timing oracle em recuperação de senha: %.0fms diferença (existente=%.0fms, inexistente=%.0fms)",
            $diff, $avgExistente, $avgInexistente
        );
    }
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. JSON malformado e edge cases de parsing
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[11] JSON Malformado / Edge Cases de Parsing\033[0m\n";

$malformedJson = [
    '',
    '{}',
    '[]',
    'null',
    'true',
    '{login: "test"}',           // JSON inválido (sem aspas na chave)
    '{"login": "test"',          // JSON incompleto
    '{"login": "test"}}',        // JSON com chave extra
    str_repeat('{"a":', 100) . '"x"' . str_repeat('}', 100), // profundamente aninhado
    '{"login": "\u0000test"}',   // null byte em string JSON
    '{"login": ' . str_repeat('[', 500) . '"x"' . str_repeat(']', 500) . '}', // array profundo
];

foreach ($malformedJson as $json) {
    $label = substr($json ?: '(vazio)', 0, 50);
    fuzzTest("JSON malformado: $label", function () use ($baseUrl, $json) {
        $res = fuzzReq('POST', "$baseUrl/api/login", null, ['Content-Type: application/json']);
        // Envia raw
        $ch = curl_init("$baseUrl/api/login");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 500) {
            return "HTTP 500 com JSON malformado: " . substr($json, 0, 80);
        }
        if ($code === 0) {
            return "Servidor não respondeu com JSON malformado";
        }
        return null;
    });
}

// ─── Resumo ───────────────────────────────────────────────────────────────────

$total = $passed + $failed;
$pct   = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n\033[1;35m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;35m║                    RESULTADO FINAL                       ║\033[0m\n";
echo "\033[1;35m╚══════════════════════════════════════════════════════════╝\033[0m\n";
printf("  \033[32m✓ Passou:   %3d\033[0m\n", $passed);
printf("  \033[31m✗ Falhou:   %3d\033[0m\n", $failed);
printf("  Taxa de sucesso: \033[1m%.1f%%\033[0m (%d/%d)\n\n", $pct, $passed, $total);

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
    echo "\033[1;32m  ✓ TODOS OS TESTES DE FUZZING PASSARAM\033[0m\n\n";
} else {
    echo "\033[1;31m  ✗ $failed TESTE(S) FALHARAM — revisar antes de deploy\033[0m\n\n";
}

exit($failed > 0 ? 1 : 0);
