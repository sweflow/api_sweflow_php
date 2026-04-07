<?php
/**
 * Sweflow API - Testes EnvController
 * Execucao: php tests/EnvConfigTest.php [BASE_URL]
 * Com auth: ADMIN_PASSWORD=senha php tests/EnvConfigTest.php http://localhost:3005
 */
declare(strict_types=1);

$baseUrl = $argv[1] ?? (getenv('APP_URL') ?: 'http://localhost:3005');
$baseUrl = rtrim($baseUrl, '/');
$passed = 0; $failed = 0; $results = [];

function envReq(string $method, string $url, array $body = [], array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => 'SweflowEnvTest/1.0',
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json','Accept: application/json'], $headers),
    ]);
    if (!empty($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs  = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false || $err) return ['status' => 0, 'body' => [], 'error' => $err];
    $rb = substr($raw, $hs); $d = json_decode($rb, true);
    return ['status' => $code, 'body' => is_array($d) ? $d : ['_raw' => $rb], 'error' => null];
}

function envTest(string $name, callable $fn): void {
    global $passed, $failed, $results;
    try {
        $r = $fn();
        if ($r === true || $r === null) {
            $passed++; $results[] = ['status'=>'PASS','name'=>$name];
            echo "\033[32m  v\033[0m $name\n";
        } else {
            $failed++; $msg = is_string($r) ? $r : 'falhou';
            $results[] = ['status'=>'FAIL','name'=>$name,'reason'=>$msg];
            echo "\033[31m  x\033[0m $name\n    \033[33m-> $msg\033[0m\n";
        }
    } catch (\Throwable $e) {
        $failed++; $results[] = ['status'=>'FAIL','name'=>$name,'reason'=>$e->getMessage()];
        echo "\033[31m  x\033[0m $name\n    \033[33m-> ".$e->getMessage()."\033[0m\n";
    }
}

echo "\n\033[1;36m[SWEFLOW - TESTES CONFIGURACOES DO AMBIENTE]\033[0m\n";
echo "Base URL: \033[1m$baseUrl\033[0m\n\n";

$ping = envReq('GET', "$baseUrl/api/status");
if ($ping['status'] === 0) { echo "\033[31mERRO: Servidor nao acessivel\033[0m\n"; exit(1); }

$adminEmail    = getenv('ADMIN_EMAIL')    ?: 'admin@admin.com';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
$token = null; $authHeader = [];

if ($adminPassword !== '') {
    $lr = envReq('POST', "$baseUrl/api/login", ['login' => $adminEmail, 'senha' => $adminPassword]);
    $token = $lr['body']['access_token'] ?? null;
    if ($token) { echo "\033[32mLogin OK\033[0m\n\n"; $authHeader = ["Authorization: Bearer $token"]; }
    else { echo "\033[31mLogin falhou: ".json_encode($lr['body'])."\033[0m\n\n"; }
} else {
    echo "\033[33mAVISO: ADMIN_PASSWORD nao definido. Testes autenticados serao pulados.\033[0m\n";
    echo "Execute: ADMIN_PASSWORD=suasenha php tests/EnvConfigTest.php\n\n";
}

// 1. Seguranca de acesso
echo "\033[1m[1] Seguranca de acesso\033[0m\n";
envTest('GET /api/env sem token retorna 401', function() use ($baseUrl) {
    $r = envReq('GET', "$baseUrl/api/env");
    return $r['status'] === 401 ? null : "Esperado 401, recebido {$r['status']}";
});
envTest('PUT /api/env sem token retorna 401', function() use ($baseUrl) {
    $r = envReq('PUT', "$baseUrl/api/env", ['vars' => ['APP_NAME' => 'Hack']]);
    return $r['status'] === 401 ? null : "Esperado 401, recebido {$r['status']}";
});

// 2. Leitura
echo "\n\033[1m[2] Leitura das variaveis\033[0m\n";
if ($token) {
    envTest('GET /api/env retorna 200 com vars', function() use ($baseUrl, $authHeader) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        if ($r['status'] !== 200) return "Esperado 200, recebido {$r['status']}";
        if (empty($r['body']['vars'])) return "Campo vars ausente";
        return null;
    });
    envTest('JWT_SECRET esta mascarado', function() use ($baseUrl, $authHeader) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        $v = $r['body']['vars']['JWT_SECRET'] ?? null;
        if ($v === null) return "JWT_SECRET ausente";
        if ($v !== '' && strlen($v) > 8 && !str_contains($v, chr(0xE2))) return "JWT_SECRET parece nao mascarado: ".substr($v,0,8)."...";
        return null;
    });
    envTest('APP_NAME retorna valor legivel', function() use ($baseUrl, $authHeader) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        $v = $r['body']['vars']['APP_NAME'] ?? '';
        return $v !== '' ? null : "APP_NAME vazio ou ausente";
    });
    envTest('DB_HOST retorna valor legivel (nao sensivel)', function() use ($baseUrl, $authHeader) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        return array_key_exists('DB_HOST', $r['body']['vars'] ?? []) ? null : "DB_HOST ausente";
    });
} else { echo "  \033[33m(pulado - sem token)\033[0m\n"; }

// 3. Bloqueios
echo "\n\033[1m[3] Bloqueios de seguranca\033[0m\n";
if ($token) {
    foreach (['DB_HOST','DB_SENHA','DB_NOME','DB_PORT','DB_USUARIO'] as $key) {
        envTest("PUT $key retorna 403", function() use ($baseUrl, $authHeader, $key) {
            $r = envReq('PUT', "$baseUrl/api/env", ['vars' => [$key => 'evil']], $authHeader);
            return $r['status'] === 403 ? null : "Esperado 403, recebido {$r['status']}";
        });
    }
    envTest('PUT chave minuscula retorna 422', function() use ($baseUrl, $authHeader) {
        $r = envReq('PUT', "$baseUrl/api/env", ['vars' => ['chave_invalida' => 'x']], $authHeader);
        return $r['status'] === 422 ? null : "Esperado 422, recebido {$r['status']}";
    });
    envTest('PUT vars vazio retorna 422', function() use ($baseUrl, $authHeader) {
        $r = envReq('PUT', "$baseUrl/api/env", ['vars' => []], $authHeader);
        return $r['status'] === 422 ? null : "Esperado 422, recebido {$r['status']}";
    });
} else { echo "  \033[33m(pulado - sem token)\033[0m\n"; }

// 4. Escrita e reflexo em tempo real
echo "\n\033[1m[4] Escrita e reflexo em tempo real\033[0m\n";
if ($token) {
    $origRes  = envReq('GET', "$baseUrl/api/env", [], $authHeader);
    $origVars = $origRes['body']['vars'] ?? [];
    $origName = $origVars['APP_NAME'] ?? 'Sweflow API';
    $origDesc = $origVars['APP_DESCRICAO'] ?? '';
    $origCors = $origVars['CORS_ALLOWED_ORIGINS'] ?? '';
    $testName = 'SweflowTest_'.time();

    envTest('PUT APP_NAME retorna 200 ok:true', function() use ($baseUrl, $authHeader, $testName) {
        $r = envReq('PUT', "$baseUrl/api/env", ['vars' => ['APP_NAME' => $testName]], $authHeader);
        if ($r['status'] !== 200) return "Esperado 200, recebido {$r['status']}: ".json_encode($r['body']);
        return empty($r['body']['ok']) ? "Campo ok ausente" : null;
    });

    envTest('GET /api/env reflete APP_NAME atualizado imediatamente', function() use ($baseUrl, $authHeader, $testName) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        $v = $r['body']['vars']['APP_NAME'] ?? '';
        return $v === $testName ? null : "Esperado: $testName, recebido: $v";
    });

    envTest('.env no disco contem APP_NAME atualizado', function() use ($testName) {
        $path = dirname(__DIR__).'/.env';
        if (!is_file($path)) return ".env nao encontrado";
        $c = file_get_contents($path) ?: '';
        $found = str_contains($c, "APP_NAME=$testName") || str_contains($c, "APP_NAME=\"$testName\"");
        return $found ? null : "APP_NAME=$testName nao encontrado no .env";
    });

    // Valor com caracteres especiais
    $special = 'API com espacos e #hash e "aspas"';
    envTest('PUT valor com espacos, # e aspas salvo corretamente', function() use ($baseUrl, $authHeader, $special) {
        $r = envReq('PUT', "$baseUrl/api/env", ['vars' => ['APP_DESCRICAO' => $special]], $authHeader);
        return $r['status'] === 200 ? null : "Esperado 200, recebido {$r['status']}";
    });
    envTest('GET reflete valor com caracteres especiais sem corrupcao', function() use ($baseUrl, $authHeader, $special) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        $v = $r['body']['vars']['APP_DESCRICAO'] ?? '';
        return $v === $special ? null : "Esperado: \"$special\", recebido: \"$v\"";
    });

    // CORS
    $testCors = 'http://localhost:3000,http://localhost:5173,https://test-'.time().'.example.com';
    envTest('PUT CORS_ALLOWED_ORIGINS com multiplas URLs', function() use ($baseUrl, $authHeader, $testCors) {
        $r = envReq('PUT', "$baseUrl/api/env", ['vars' => ['CORS_ALLOWED_ORIGINS' => $testCors]], $authHeader);
        return $r['status'] === 200 ? null : "Esperado 200, recebido {$r['status']}";
    });
    envTest('GET reflete CORS_ALLOWED_ORIGINS atualizado', function() use ($baseUrl, $authHeader, $testCors) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        $v = $r['body']['vars']['CORS_ALLOWED_ORIGINS'] ?? '';
        return $v === $testCors ? null : "Esperado: $testCors, recebido: $v";
    });

    // Restaura
    envReq('PUT', "$baseUrl/api/env", ['vars' => [
        'APP_NAME' => $origName, 'APP_DESCRICAO' => $origDesc, 'CORS_ALLOWED_ORIGINS' => $origCors,
    ]], $authHeader);
    envTest('Restauracao: APP_NAME voltou ao original', function() use ($baseUrl, $authHeader, $origName) {
        $r = envReq('GET', "$baseUrl/api/env", [], $authHeader);
        $v = $r['body']['vars']['APP_NAME'] ?? '';
        return $v === $origName ? null : "Esperado: $origName, recebido: $v";
    });
} else { echo "  \033[33m(pulado - sem token)\033[0m\n"; }

// 5. Protecao de campos sensiveis
echo "\n\033[1m[5] Protecao de campos sensiveis\033[0m\n";
if ($token) {
    envTest('PUT JWT_SECRET com placeholder nao sobrescreve', function() use ($baseUrl, $authHeader) {
        envReq('PUT', "$baseUrl/api/env", ['vars' => ['JWT_SECRET' => '........']], $authHeader);
        $path = dirname(__DIR__).'/.env';
        $c = file_get_contents($path) ?: '';
        return str_contains($c, 'JWT_SECRET=........') ? "JWT_SECRET foi sobrescrito com placeholder!" : null;
    });
    envTest('PUT JWT_SECRET vazio nao esvazia o valor', function() use ($baseUrl, $authHeader) {
        envReq('PUT', "$baseUrl/api/env", ['vars' => ['JWT_SECRET' => '']], $authHeader);
        $path = dirname(__DIR__).'/.env';
        $c = file_get_contents($path) ?: '';
        return preg_match('/^JWT_SECRET=$/m', $c) ? "JWT_SECRET foi esvaziado!" : null;
    });
} else { echo "  \033[33m(pulado - sem token)\033[0m\n"; }

// 6. Concorrencia
echo "\n\033[1m[6] Concorrencia - escritas simultaneas\033[0m\n";
if ($token) {
    envTest('10 escritas paralelas nao corrompem o .env', function() use ($baseUrl, $token) {
        $mh = curl_multi_init(); $handles = [];
        for ($i = 0; $i < 10; $i++) {
            $ch = curl_init("$baseUrl/api/env");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json',"Authorization: Bearer $token"],
                CURLOPT_POSTFIELDS => json_encode(['vars' => ['APP_VERSION' => "1.0.$i"]]),
            ]);
            curl_multi_add_handle($mh, $ch); $handles[] = $ch;
        }
        do { $s = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh); } while ($active && $s === CURLM_OK);
        $errors = 0;
        foreach ($handles as $ch) {
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 500) $errors++;
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
        if ($errors > 0) return "$errors escritas retornaram 500";
        $c = file_get_contents(dirname(__DIR__).'/.env') ?: '';
        if (empty($c) || !str_contains($c, 'APP_NAME')) return ".env corrompido apos escritas concorrentes";
        return null;
    });
    // Restaura APP_VERSION
    envReq('PUT', "$baseUrl/api/env", ['vars' => ['APP_VERSION' => '1.0.0']], $authHeader);
} else { echo "  \033[33m(pulado - sem token)\033[0m\n"; }

// Resumo
$total = $passed + $failed;
$pct   = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
echo "\n\033[1;36m[RESULTADO FINAL]\033[0m\n";
printf("  Passou: %d  Falhou: %d  Taxa: %.1f%%\n\n", $passed, $failed, $pct);
if ($failed > 0) {
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') echo "  x {$r['name']}\n    -> ".($r['reason'] ?? '')."\n";
    }
    echo "\n";
}
if ($failed === 0 && $passed > 0) echo "\033[1;32m  TODOS OS TESTES PASSARAM\033[0m\n\n";
elseif ($passed === 0) echo "\033[33m  Nenhum teste executado. Execute: ADMIN_PASSWORD=suasenha php tests/EnvConfigTest.php\033[0m\n\n";
else echo "\033[1;31m  $failed TESTE(S) FALHARAM\033[0m\n\n";
exit($failed > 0 ? 1 : 0);
