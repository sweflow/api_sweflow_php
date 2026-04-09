<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Utils\Sanitizer;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\SecurityHeadersMiddleware;
use Src\Kernel\Nonce;

/**
 * Auditoria de segurança — 25 categorias OWASP/CWE testáveis em unidade.
 */
class SecurityAuditTest extends TestCase
{
    private array $originalServer = [];
    private array $originalEnv    = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalEnv    = $_ENV;
        Nonce::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV    = $this->originalEnv;
        Nonce::reset();
        parent::tearDown();
    }

    private function buildRequest(string $uri = '/api/test'): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_HOST']   = 'api.example.com';
        return new Request([], [], [], 'GET', $uri);
    }

    private function next(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    private function sourceOf(string $class): string
    {
        return file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    // ── 1. BROKEN ACCESS CONTROL ─────────────────────────────────────

    public function test_admin_only_bloqueia_sem_autenticacao(): void
    {
        $res = (new AdminOnlyMiddleware())->handle($this->buildRequest('/api/admin'), $this->next());
        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_admin_only_bloqueia_usuario_comum(): void
    {
        $req = $this->buildRequest('/api/admin')
            ->withAttribute('auth_payload', (object)['nivel_acesso' => 'usuario'])
            ->withAttribute('auth_user', new class { public function getNivelAcesso(): string { return 'usuario'; } })
            ->withAttribute('token_signed_with_api_secret', false);
        $this->assertSame(403, (new AdminOnlyMiddleware())->handle($req, $this->next())->getStatusCode());
    }

    public function test_admin_only_bloqueia_admin_sem_api_secret(): void
    {
        // admin_system com token assinado com JWT_SECRET (não JWT_API_SECRET) deve ser bloqueado
        $req = $this->buildRequest('/api/admin')
            ->withAttribute('auth_payload', (object)['nivel_acesso' => 'admin_system'])
            ->withAttribute('auth_user', new class { public function getNivelAcesso(): string { return 'admin_system'; } })
            ->withAttribute('token_signed_with_api_secret', false);
        $this->assertSame(403, (new AdminOnlyMiddleware())->handle($req, $this->next())->getStatusCode(),
            'admin_system sem JWT_API_SECRET deve ser bloqueado — previne escalada de privilégio');
    }

    public function test_admin_only_bloqueia_moderador(): void
    {
        $req = $this->buildRequest('/api/admin')
            ->withAttribute('auth_payload', (object)['nivel_acesso' => 'moderador'])
            ->withAttribute('auth_user', new class { public function getNivelAcesso(): string { return 'moderador'; } })
            ->withAttribute('token_signed_with_api_secret', false);
        $this->assertSame(403, (new AdminOnlyMiddleware())->handle($req, $this->next())->getStatusCode());
    }

    public function test_admin_only_permite_api_token_puro(): void
    {
        $req    = $this->buildRequest('/api/admin')->withAttribute('api_token', true);
        $passed = false;
        (new AdminOnlyMiddleware())->handle($req, function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed);
    }

    public function test_admin_only_permite_admin_system_com_api_secret(): void
    {
        $req = $this->buildRequest('/api/admin')
            ->withAttribute('auth_payload', (object)['nivel_acesso' => 'admin_system'])
            ->withAttribute('auth_user', new class { public function getNivelAcesso(): string { return 'admin_system'; } })
            ->withAttribute('token_signed_with_api_secret', true);
        $passed = false;
        (new AdminOnlyMiddleware())->handle($req, function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed);
    }

    // ── 2. AUTENTICAÇÃO — UUID malformado, blacklist, regex ──────────

    public function test_uuid_path_traversal_rejeitado(): void
    {
        $this->assertSame('', Sanitizer::uuid('../../../etc/passwd'));
        $this->assertSame('', Sanitizer::uuid("'; DROP TABLE users; --"));
        $this->assertSame('', Sanitizer::uuid('<script>alert(1)</script>'));
    }

    public function test_uuid_valido_aceito(): void
    {
        $this->assertSame('f47ac10b-58cc-4372-a567-0e02b2c3d479', Sanitizer::uuid('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
    }

    public function test_uuid_v1_rejeitado(): void
    {
        $this->assertSame('', Sanitizer::uuid('550e8400-e29b-11d4-a716-446655440000'));
    }

    public function test_auth_middleware_verifica_blacklist(): void
    {
        $this->assertStringContainsString('isRevoked',
            $this->sourceOf(\Src\Kernel\Middlewares\AuthHybridMiddleware::class),
            'AuthHybridMiddleware deve verificar blacklist de tokens');
    }

    public function test_auth_middleware_valida_uuid_com_regex(): void
    {
        $this->assertStringContainsString('preg_match',
            $this->sourceOf(\Src\Kernel\Middlewares\AuthHybridMiddleware::class),
            'AuthHybridMiddleware deve validar UUID com regex antes de buscar no banco');
    }

    // ── 3. EXPOSIÇÃO DE DADOS SENSÍVEIS ──────────────────────────────

    public function test_resposta_nao_expoe_stack_trace(): void
    {
        $body = Response::json(['status' => 'error', 'message' => 'Erro interno.'], 500)->getBody();
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertArrayNotHasKey('line', $body);
    }

    public function test_sanitizer_password_nao_altera_conteudo(): void
    {
        $senha = '<strong>P@ss!123</strong>';
        $this->assertSame($senha, Sanitizer::password($senha),
            'Sanitizer::password não deve alterar senha — senhas podem ter qualquer char');
    }

    public function test_sanitizer_password_limita_128_chars(): void
    {
        $this->assertLessThanOrEqual(128, strlen(Sanitizer::password(str_repeat('a', 200))),
            'Senha limitada a 128 chars previne DoS via bcrypt');
    }

    public function test_nivel_acesso_invalido_rejeitado(): void
    {
        foreach (['superadmin', 'root', 'god', 'owner', 'admin_system2', '<script>'] as $nivel) {
            $this->assertSame('', Sanitizer::nivelAcesso($nivel), "Nível '$nivel' deve ser rejeitado");
        }
    }

    // ── 4. INJEÇÃO — SQL, XSS, Path Traversal ────────────────────────

    public function test_sanitizer_string_remove_tags_html(): void
    {
        $this->assertSame('alerta', Sanitizer::string('<script>alerta</script>'));
        $this->assertSame('texto', Sanitizer::string('<b>texto</b>'));
        $this->assertSame('', Sanitizer::string('<img src=x onerror=alert(1)>'));
    }

    public function test_sanitizer_string_remove_null_bytes(): void
    {
        $this->assertSame('normal', Sanitizer::string("nor\x00mal"));
        $this->assertSame('normal', Sanitizer::string("nor\x1Fmal"));
    }

    public function test_sanitizer_email_rejeita_sql_injection(): void
    {
        $this->assertSame('', Sanitizer::email("' OR '1'='1"));
        $this->assertSame('', Sanitizer::email("admin'--"));
        $this->assertSame('', Sanitizer::email("'; DROP TABLE users; --"));
    }

    public function test_sanitizer_email_rejeita_xss(): void
    {
        $this->assertSame('', Sanitizer::email('<script>alert(1)</script>@evil.com'));
    }

    public function test_sanitizer_username_rejeita_sql_injection(): void
    {
        // username remove chars especiais — SQL injection nao passa como string executavel
        $result = Sanitizer::username("'; DROP TABLE users; --");
        $this->assertStringNotContainsString("'", $result, 'Aspas simples devem ser removidas');
        $this->assertStringNotContainsString(";", $result, 'Ponto-e-virgula deve ser removido');
        $this->assertStringNotContainsString("--", $result, 'Comentario SQL deve ser removido');
        $this->assertSame('admin', Sanitizer::username("admin'--"));
    }

    public function test_sanitizer_username_permite_apenas_chars_validos(): void
    {
        $this->assertSame('user_name.123', Sanitizer::username('user_name.123'));
        $this->assertSame('username', Sanitizer::username('user@name!'));
    }

    public function test_sanitizer_url_rejeita_javascript_protocol(): void
    {
        $this->assertSame('', Sanitizer::url('javascript:alert(1)'));
        $this->assertSame('', Sanitizer::url('data:text/html,<script>alert(1)</script>'));
        $this->assertSame('', Sanitizer::url('file:///etc/passwd'));
    }

    public function test_sanitizer_url_aceita_https(): void
    {
        $this->assertSame('https://cdn.example.com/avatar.jpg', Sanitizer::url('https://cdn.example.com/avatar.jpg'));
    }

    public function test_sanitizer_text_remove_tags(): void
    {
        $this->assertSame('texto limpo', Sanitizer::text('<p>texto limpo</p>'));
        // strip_tags remove a tag <script> mas preserva o texto — sem a tag nao e executavel
        $result = Sanitizer::text('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $result, 'Tag script deve ser removida');
        $this->assertStringNotContainsString('</script>', $result, 'Tag de fechamento deve ser removida');
    }

    public function test_sanitizer_string_limita_tamanho(): void
    {
        $this->assertSame(255, mb_strlen(Sanitizer::string(str_repeat('a', 300))));
    }

    // ── 5. XSS — CSP nonce ───────────────────────────────────────────

    public function test_csp_nonce_previne_xss_inline(): void
    {
        $csp = Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy'];
        // script-src usa nonce — scripts inline sem nonce sao bloqueados
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        // style-src pode ter unsafe-inline (CSS nao executa JS), mas script-src nao deve
        $this->assertDoesNotMatchRegularExpression(
            "/script-src[^;]*'unsafe-inline'/",
            $csp,
            'script-src nao deve conter unsafe-inline'
        );
    }

    public function test_csp_api_bloqueia_todo_script(): void
    {
        $this->assertStringContainsString("default-src 'none'",
            Response::json(['ok' => true])->getHeaders()['Content-Security-Policy']);
    }

    public function test_nonce_tem_entropia_suficiente(): void
    {
        $decoded = base64_decode(Nonce::get(), true);
        $this->assertNotFalse($decoded);
        $this->assertGreaterThanOrEqual(16, strlen($decoded));
    }

    // ── 6. CSRF — CORP/COOP/COEP ─────────────────────────────────────

    public function test_corp_impede_carregamento_cross_origin(): void
    {
        $this->assertSame('same-origin',
            Response::json(['ok' => true])->getHeaders()['Cross-Origin-Resource-Policy']);
    }

    public function test_coop_isola_browsing_context(): void
    {
        $this->assertSame('same-origin',
            Response::json(['ok' => true])->getHeaders()['Cross-Origin-Opener-Policy']);
    }

    public function test_coep_exige_corp_em_recursos_embarcados(): void
    {
        $this->assertSame('require-corp',
            Response::json(['ok' => true])->getHeaders()['Cross-Origin-Embedder-Policy']);
    }

    // ── 7. SECURITY MISCONFIGURATION — headers obrigatórios ──────────

    #[\PHPUnit\Framework\Attributes\DataProvider('headersObrigatoriosProvider')]
    public function test_header_obrigatorio_presente(string $header): void
    {
        $this->assertArrayHasKey($header, Response::json(['ok' => true])->getHeaders());
    }

    public static function headersObrigatoriosProvider(): array
    {
        return [
            ['Content-Security-Policy'], ['X-Frame-Options'], ['X-Content-Type-Options'],
            ['Referrer-Policy'], ['Permissions-Policy'],
            ['Cross-Origin-Resource-Policy'], ['Cross-Origin-Opener-Policy'], ['Cross-Origin-Embedder-Policy'],
        ];
    }

    public function test_x_xss_protection_ausente_deprecated(): void
    {
        $this->assertArrayNotHasKey('X-XSS-Protection',
            Response::json(['ok' => true])->getHeaders(),
            'X-XSS-Protection é deprecated e deve ser removido');
    }

    public function test_content_type_json_correto(): void
    {
        $ct = Response::json(['ok' => true])->getHeaders()['Content-Type'];
        $this->assertStringContainsString('application/json', $ct);
        $this->assertStringContainsString('charset=utf-8', $ct);
    }

    // ── 8. TIMING ATTACKS ────────────────────────────────────────────

    public function test_login_usa_enforce_min_response_time(): void
    {
        $source = $this->sourceOf(\Src\Modules\Auth\Controllers\AuthController::class);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'enforceMinResponseTime'),
            'enforceMinResponseTime deve ser chamado em sucesso, falha e erro do login');
    }

    public function test_recuperacao_senha_resposta_generica(): void
    {
        $this->assertStringContainsString('Se o e-mail informado estiver cadastrado',
            $this->sourceOf(\Src\Modules\Auth\Controllers\AuthController::class),
            'Recuperação de senha deve usar resposta genérica para não revelar e-mails');
    }

    // ── 9. CORS — sem wildcard com credenciais ────────────────────────

    public function test_cors_nao_reflete_origem_nao_autorizada(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        $_SERVER['HTTP_ORIGIN']       = 'https://attacker.com';
        $origin = Response::json(['ok' => true])->getHeaders()['Access-Control-Allow-Origin'] ?? '';
        $this->assertNotSame('https://attacker.com', $origin);
    }

    public function test_cors_aceita_origem_autorizada(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        $_SERVER['HTTP_ORIGIN']       = 'https://app.example.com';
        $origin = Response::json(['ok' => true])->getHeaders()['Access-Control-Allow-Origin'] ?? '';
        $this->assertSame('https://app.example.com', $origin);
    }

    public function test_cors_vary_origin_presente(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();
        $this->assertArrayHasKey('Vary', $headers);
        $this->assertStringContainsString('Origin', $headers['Vary']);
    }

    public function test_cors_sem_wildcard_com_credenciais(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();
        $origin  = $headers['Access-Control-Allow-Origin'] ?? '';
        $creds   = $headers['Access-Control-Allow-Credentials'] ?? 'false';
        if ($origin === '*') {
            $this->assertSame('false', $creds, 'Wildcard CORS não pode ter credentials: true');
        } else {
            $this->assertTrue(true); // origem específica — ok
        }
    }

    // ── 10. CLICKJACKING ─────────────────────────────────────────────

    public function test_x_frame_options_deny(): void
    {
        $this->assertSame('DENY', Response::json(['ok' => true])->getHeaders()['X-Frame-Options']);
    }

    public function test_csp_frame_ancestors_none_api(): void
    {
        $this->assertStringContainsString("frame-ancestors 'none'",
            Response::json(['ok' => true])->getHeaders()['Content-Security-Policy']);
    }

    public function test_csp_frame_ancestors_none_html(): void
    {
        $this->assertStringContainsString("frame-ancestors 'none'",
            Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy']);
    }

    // ── 11. UPLOAD INSEGURO ───────────────────────────────────────────

    public function test_upload_tem_whitelist_de_mimes(): void
    {
        $source = $this->sourceOf(\Src\Modules\Usuario\Controllers\UsuarioController::class);
        $this->assertStringContainsString('image/jpeg', $source);
        $this->assertStringContainsString('image/png', $source);
        $this->assertStringContainsString('image/webp', $source);
    }

    public function test_upload_tem_limite_de_tamanho(): void
    {
        $this->assertStringContainsString('5 * 1024 * 1024',
            $this->sourceOf(\Src\Modules\Usuario\Controllers\UsuarioController::class),
            'Upload deve ter limite de 5MB');
    }

    public function test_upload_valida_conteudo_real_com_getimagesize(): void
    {
        $this->assertStringContainsString('getimagesize',
            $this->sourceOf(\Src\Modules\Usuario\Controllers\UsuarioController::class),
            'Upload deve validar conteúdo real da imagem, não apenas extensão');
    }

    public function test_upload_nome_gerado_pelo_servidor(): void
    {
        $this->assertStringContainsString('gerarNomeArquivo',
            $this->sourceOf(\Src\Modules\Usuario\Controllers\UsuarioController::class),
            'Nome do arquivo deve ser gerado pelo servidor, não usar nome do cliente');
    }

    // ── 12. DESERIALIZAÇÃO INSEGURA ───────────────────────────────────

    public function test_json_decode_com_profundidade_limitada_no_request_factory(): void
    {
        // O limite de profundidade está no RequestFactory, não no AuthController
        $source = $this->sourceOf(\Src\Kernel\Http\Request\RequestFactory::class);
        $this->assertStringContainsString('json_decode($rawBody, true, 32)',
            $source,
            'RequestFactory deve limitar profundidade do json_decode para evitar DoS');
    }

    public function test_body_limitado_no_request_factory(): void
    {
        // O limite de payload está no RequestFactory
        $source = $this->sourceOf(\Src\Kernel\Http\Request\RequestFactory::class);
        $this->assertStringContainsString('MAX_PAYLOAD_KB',
            $source,
            'RequestFactory deve limitar tamanho do body via MAX_PAYLOAD_KB');
    }

    public function test_json_profundidade_32_rejeita_json_muito_profundo(): void
    {
        $jsonProfundo = str_repeat('{"a":', 35) . '"v"' . str_repeat('}', 35);
        $this->assertNull(json_decode($jsonProfundo, true, 32),
            'JSON com profundidade > 32 deve ser rejeitado');
    }

    // ── 13. RATE LIMITING — brute force ──────────────────────────────

    public function test_threat_scorer_bloqueia_apos_5_logins_falhos(): void
    {
        $dir = sys_get_temp_dir() . '/vupi_audit_' . uniqid();
        mkdir($dir, 0750, true);
        $scorer = new \Src\Kernel\Support\ThreatScorer(
            new \Src\Kernel\Support\Storage\FileRateLimitStorage($dir)
        );

        for ($i = 0; $i < 5; $i++) {
            $scorer->add('1.2.3.4', \Src\Kernel\Support\ThreatScorer::SCORE_LOGIN_FAIL);
        }
        $this->assertTrue($scorer->shouldBlock('1.2.3.4'),
            '5 logins falhos (5x30=150pts) deve atingir threshold de bloqueio');

        foreach (glob($dir . '/*.json') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }

    // ── 14. PERMISSIONS-POLICY ───────────────────────────────────────

    public function test_permissions_policy_bloqueia_sensores(): void
    {
        $pp = Response::json(['ok' => true])->getHeaders()['Permissions-Policy'];
        $this->assertStringContainsString('geolocation=()', $pp);
        $this->assertStringContainsString('microphone=()', $pp);
        $this->assertStringContainsString('camera=()', $pp);
    }

    // ── 15. HSTS ─────────────────────────────────────────────────────

    public function test_hsts_presente_em_https(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $hsts = Response::json(['ok' => true])->getHeaders()['Strict-Transport-Security'];
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
        $this->assertStringContainsString('preload', $hsts);
    }

    public function test_hsts_ausente_em_http(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $this->assertArrayNotHasKey('Strict-Transport-Security',
            Response::json(['ok' => true])->getHeaders());
    }

    // ── 16. CSP — object-src, base-uri, form-action ──────────────────

    public function test_csp_object_src_none_bloqueia_plugins(): void
    {
        $this->assertStringContainsString("object-src 'none'",
            Response::json(['ok' => true])->getHeaders()['Content-Security-Policy']);
    }

    public function test_csp_base_uri_none_em_api(): void
    {
        // API nao tem <base> tag — 'none' e mais restritivo que 'self'
        $this->assertStringContainsString("base-uri 'none'",
            Response::json(['ok' => true])->getHeaders()['Content-Security-Policy'],
            "API deve usar base-uri 'none' — mais restritivo que 'self'");
    }

    public function test_csp_form_action_self_previne_exfiltracao(): void
    {
        $this->assertStringContainsString("form-action 'self'",
            Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy']);
    }

    // ── 17. REFERRER POLICY ──────────────────────────────────────────

    public function test_referrer_policy_api_e_no_referrer(): void
    {
        $this->assertSame('no-referrer',
            Response::json(['ok' => true])->getHeaders()['Referrer-Policy'],
            'API usa no-referrer — sem motivo para vazar URL de origem');
    }

    // ── 18. NIVEL DE ACESSO — whitelist estrita ───────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('niveisValidosProvider')]
    public function test_nivel_acesso_valido_aceito(string $nivel): void
    {
        $this->assertSame($nivel, Sanitizer::nivelAcesso($nivel));
    }

    public static function niveisValidosProvider(): array
    {
        return [['usuario'], ['moderador'], ['admin'], ['admin_system']];
    }

    // ── 19. MIDDLEWARE PIPELINE — cobre respostas sem headers ─────────

    public function test_security_middleware_adiciona_headers_em_resposta_simples(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $req = $this->buildRequest('/api/test');
        $res = $mw->handle($req, fn($r) => new \Src\Kernel\Http\Response\Response(
            ['ok' => true], 200, ['Content-Type' => 'application/json']
        ));
        $this->assertArrayHasKey('Content-Security-Policy', $res->getHeaders());
        $this->assertArrayHasKey('X-Frame-Options', $res->getHeaders());
        $this->assertArrayHasKey('Cross-Origin-Resource-Policy', $res->getHeaders());
    }

    // ── 20. ENUMERAÇÃO DE USUÁRIOS ────────────────────────────────────

    public function test_reenvio_verificacao_resposta_generica(): void
    {
        $this->assertStringContainsString('Se o e-mail existir',
            $this->sourceOf(\Src\Modules\Usuario\Controllers\UsuarioController::class),
            'Reenvio de verificação deve usar resposta genérica');
    }

    public function test_login_mensagem_generica_em_falha(): void
    {
        $this->assertStringContainsString('Credenciais inválidas',
            $this->sourceOf(\Src\Modules\Auth\Controllers\AuthController::class),
            'Login deve retornar mensagem genérica — não revelar se usuário existe');
    }

    // ── 21. BLACKLIST DE TOKENS ───────────────────────────────────────

    public function test_logout_revoga_token_na_blacklist(): void
    {
        $source = $this->sourceOf(\Src\Modules\Auth\Controllers\AuthController::class);
        $this->assertStringContainsString('accessBlacklist', $source,
            'AuthController deve ter referência ao repositório de blacklist');
        $this->assertStringContainsString('revoke', $source,
            'AuthController deve chamar revoke() no logout');
    }

    // ── 22. HTTPS ENFORCEMENT ────────────────────────────────────────

    public function test_login_verifica_https_antes_de_autenticar(): void
    {
        $source = $this->sourceOf(\Src\Modules\Auth\Controllers\AuthController::class);
        $this->assertStringContainsString('requiresHttps', $source);
        $this->assertStringContainsString('isHttps', $source);
    }

    // ── 23. AUDIT LOGGING ────────────────────────────────────────────

    public function test_login_registra_sucesso_e_falha_no_audit(): void
    {
        $source = $this->sourceOf(\Src\Modules\Auth\Controllers\AuthController::class);
        $this->assertStringContainsString("'auth.login.success'", $source);
        $this->assertStringContainsString("'auth.login.failed'", $source);
        $this->assertStringContainsString("'auth.logout'", $source);
    }

    // ── 24. SANITIZER — positiveInt previne overflow ──────────────────

    public function test_positive_int_rejeita_negativo_e_zero(): void
    {
        $this->assertSame(1, Sanitizer::positiveInt(-999));
        $this->assertSame(1, Sanitizer::positiveInt(0));
    }

    public function test_positive_int_respeita_maximo(): void
    {
        $this->assertSame(100, Sanitizer::positiveInt(9999, 1, 100));
    }

    // ── 25. COOKIE SECURITY ───────────────────────────────────────────

    public function test_cookie_httponly_true_por_padrao(): void
    {
        $this->assertTrue(\Src\Kernel\Support\CookieConfig::options(0)['httponly'],
            'Cookie deve ter HttpOnly=true — previne acesso via JavaScript');
    }

    public function test_cookie_samesite_lax_por_padrao(): void
    {
        $this->assertSame('Lax', \Src\Kernel\Support\CookieConfig::options(0)['samesite'],
            'Cookie deve ter SameSite=Lax — mitiga CSRF');
    }

    public function test_cookie_samesite_none_sem_secure_cai_para_lax(): void
    {
        $_ENV['COOKIE_SAMESITE'] = 'None';
        $_ENV['COOKIE_SECURE']   = 'false';
        $this->assertSame('Lax', \Src\Kernel\Support\CookieConfig::options(0)['samesite'],
            'SameSite=None sem Secure deve cair para Lax');
    }

    public function test_cookie_domain_remove_protocolo(): void
    {
        $_ENV['COOKIE_DOMAIN'] = 'https://api.example.com';
        $this->assertSame('api.example.com', \Src\Kernel\Support\CookieConfig::options(0)['domain']);
    }
}
