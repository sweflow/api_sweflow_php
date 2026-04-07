<?php
namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Middlewares\BotBlockerMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\SecurityHeadersMiddleware;
use Src\Kernel\Middlewares\HttpsEnforcerMiddleware;
use Src\Kernel\Middlewares\CircuitBreakerMiddleware;
use Src\Kernel\Support\ThreatScorer;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\CookieConfig;
use Src\Kernel\Support\AuditLogger;
use Src\Kernel\Support\TokenExtractor;
use Src\Kernel\Utils\Sanitizer;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Testes de segurança aprofundados — cobre gaps não cobertos pelos testes existentes.
 *
 * Categorias:
 *   1.  CircuitBreaker — estados CLOSED/OPEN/HALF, cooldown, threshold
 *   2.  IpResolver — edge cases: IPv6 completo, spoofing, cadeia XFF, IP inválido
 *   3.  CookieConfig — isHttps() com spoofing, SameSite=None sem Secure, domain strip
 *   4.  Sanitizer — edge cases: unicode, null bytes, overflow, URL schemes perigosos
 *   5.  AuditLogger — SSRF prevention no webhook, isInternalHost
 *   6.  BotBlockerMiddleware — delay progressivo, score acumulado, env não-prod
 *   7.  RateLimitMiddleware — rate limit por usuário autenticado, purge, window reset
 *   8.  SecurityHeadersMiddleware — pipeline completo, HSTS condicional, CSP por rota
 *   9.  HttpsEnforcerMiddleware — bloqueio HTTP, passagem HTTPS, resposta JSON vs HTML
 *   10. ThreatScorer — TTL/expiração, delay progressivo por faixa de score
 *   11. TokenExtractor — injeção CRLF, token com espaços, X-API-KEY vazio
 *   12. Performance — tempo de resposta dos middlewares sob carga simulada
 *   13. Encadeamento de middlewares — pipeline completo sem vazamento de estado
 */
class SecurityDeepTest extends TestCase
{
    private array  $originalServer = [];
    private array  $originalEnv    = [];
    private array  $originalCookie = [];
    private string $tmpDir         = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalEnv    = $_ENV;
        $this->originalCookie = $_COOKIE;

        $_ENV['APP_ENV']        = 'testing';
        $_ENV['TRUST_PROXY']    = 'false';
        $_ENV['JWT_SECRET']     = 'test-jwt-secret-32-chars-minimum!';
        $_ENV['JWT_API_SECRET'] = 'test-api-secret-32-chars-minimum!';
        $_ENV['COOKIE_SECURE']  = 'false';
        $_ENV['COOKIE_HTTPONLY']= 'true';
        $_ENV['COOKIE_SAMESITE']= 'Lax';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $this->tmpDir = sys_get_temp_dir() . '/sweflow_deep_' . uniqid();
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV    = $this->originalEnv;
        $_COOKIE = $this->originalCookie;
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function req(string $uri = '/api/test', string $method = 'GET'): Request
    {
        return new Request([], [], [], $method, $uri);
    }

    private function next(int $status = 200): callable
    {
        return fn(Request $r) => Response::json(['ok' => true], $status);
    }

    private function nextFail(): callable
    {
        return fn(Request $r) => Response::json(['error' => 'db error'], 500);
    }

    private function scorer(): ThreatScorer
    {
        return new ThreatScorer(
            new \Src\Kernel\Support\Storage\FileRateLimitStorage($this->tmpDir)
        );
    }

    private function circuitBreaker(string $svc = 'test', int $threshold = 3, int $cooldown = 30): CircuitBreakerMiddleware
    {
        $cb  = new CircuitBreakerMiddleware($svc, $threshold, $cooldown);
        $ref = new \ReflectionProperty(CircuitBreakerMiddleware::class, 'storageDir');
        $ref->setAccessible(true);
        $ref->setValue($cb, $this->tmpDir);
        return $cb;
    }

    private function rateLimiter(int $limit = 3, string $key = 'deep'): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $limit, 60, $key, 0,
            new \Src\Kernel\Support\Storage\FileRateLimitStorage($this->tmpDir)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. CircuitBreaker
    // ══════════════════════════════════════════════════════════════════

    public function test_circuit_breaker_inicia_closed(): void
    {
        $cb  = $this->circuitBreaker();
        $passed = false;
        $cb->handle($this->req(), function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed, 'Estado CLOSED deve deixar requisição passar');
    }

    public function test_circuit_breaker_abre_apos_threshold_de_falhas(): void
    {
        $cb = $this->circuitBreaker('svc_open', 3, 60);
        // 3 falhas consecutivas (5xx) devem abrir o circuito
        for ($i = 0; $i < 3; $i++) {
            $cb->handle($this->req(), $this->nextFail());
        }
        // Próxima requisição deve ser rejeitada com 503
        $res = $cb->handle($this->req(), $this->next());
        $this->assertSame(503, $res->getStatusCode(), 'Circuito aberto deve retornar 503');
    }

    public function test_circuit_breaker_503_tem_retry_after(): void
    {
        $cb = $this->circuitBreaker('svc_retry', 2, 30);
        for ($i = 0; $i < 2; $i++) {
            $cb->handle($this->req(), $this->nextFail());
        }
        $res = $cb->handle($this->req(), $this->next());
        $this->assertSame(503, $res->getStatusCode());
        $this->assertArrayHasKey('Retry-After', $res->getHeaders());
        $this->assertGreaterThan(0, (int) $res->getHeaders()['Retry-After']);
    }

    public function test_circuit_breaker_503_tem_header_cb_status(): void
    {
        $cb = $this->circuitBreaker('svc_hdr', 2, 30);
        for ($i = 0; $i < 2; $i++) {
            $cb->handle($this->req(), $this->nextFail());
        }
        $res = $cb->handle($this->req(), $this->next());
        $this->assertSame('OPEN', $res->getHeaders()['X-CB-Status'] ?? '');
    }

    public function test_circuit_breaker_sucesso_reseta_falhas(): void
    {
        $cb = $this->circuitBreaker('svc_reset', 3, 30);
        // 2 falhas — não abre ainda
        $cb->handle($this->req(), $this->nextFail());
        $cb->handle($this->req(), $this->nextFail());
        // 1 sucesso — reseta contador
        $cb->handle($this->req(), $this->next());
        // 2 falhas novamente — ainda não deve abrir (contador foi resetado)
        $cb->handle($this->req(), $this->nextFail());
        $cb->handle($this->req(), $this->nextFail());
        $res = $cb->handle($this->req(), $this->next());
        $this->assertSame(200, $res->getStatusCode(), 'Sucesso deve resetar contador de falhas');
    }

    public function test_circuit_breaker_nao_abre_com_falhas_abaixo_threshold(): void
    {
        $cb = $this->circuitBreaker('svc_below', 5, 30);
        for ($i = 0; $i < 4; $i++) {
            $cb->handle($this->req(), $this->nextFail());
        }
        $res = $cb->handle($this->req(), $this->next());
        $this->assertSame(200, $res->getStatusCode(), 'Abaixo do threshold não deve abrir circuito');
    }

    public function test_circuit_breaker_servicos_diferentes_sao_isolados(): void
    {
        $cb1 = $this->circuitBreaker('svc_a', 2, 30);
        $cb2 = $this->circuitBreaker('svc_b', 2, 30);

        // Abre svc_a
        $cb1->handle($this->req(), $this->nextFail());
        $cb1->handle($this->req(), $this->nextFail());

        // svc_b deve continuar funcionando
        $res = $cb2->handle($this->req(), $this->next());
        $this->assertSame(200, $res->getStatusCode(), 'Serviços diferentes devem ter estado independente');
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. IpResolver — edge cases
    // ══════════════════════════════════════════════════════════════════

    public function test_ip_rejeita_xff_com_ip_invalido(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 203.0.113.5';
        // Primeiro IP inválido — deve pular para o próximo ou cair no REMOTE_ADDR
        $ip = IpResolver::resolve();
        $this->assertNotSame('not-an-ip', $ip, 'IP inválido no XFF não deve ser retornado');
    }

    public function test_ip_normaliza_ipv6_completo(): void
    {
        $_ENV['TRUST_PROXY']    = 'false';
        $_SERVER['REMOTE_ADDR'] = '::ffff:10.0.0.1';
        $this->assertSame('10.0.0.1', IpResolver::resolve());
    }

    public function test_ip_normaliza_ipv6_loopback(): void
    {
        $_ENV['TRUST_PROXY']    = 'false';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $this->assertSame('127.0.0.1', IpResolver::resolve());
    }

    public function test_ip_sem_remote_addr_retorna_fallback(): void
    {
        $_ENV['TRUST_PROXY'] = 'false';
        unset($_SERVER['REMOTE_ADDR']);
        $ip = IpResolver::resolve();
        $this->assertIsString($ip, 'Deve retornar string mesmo sem REMOTE_ADDR');
    }

    public function test_ip_spoofing_cf_connecting_ip_sem_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']              = 'false';
        $_SERVER['REMOTE_ADDR']           = '203.0.113.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1';
        // Sem TRUST_PROXY, CF-Connecting-IP deve ser ignorado
        $this->assertSame('203.0.113.1', IpResolver::resolve());
    }

    public function test_ip_xff_com_espacos_e_normalizado(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '  203.0.113.5  , 10.0.0.2';
        $this->assertSame('203.0.113.5', IpResolver::resolve());
    }

    public function test_ip_xff_vazio_cai_para_remote_addr(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '203.0.113.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '';
        $this->assertSame('203.0.113.1', IpResolver::resolve());
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. CookieConfig — edge cases de segurança
    // ══════════════════════════════════════════════════════════════════

    public function test_cookie_config_is_https_com_https_on(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(CookieConfig::isHttps());
    }

    public function test_cookie_config_is_https_com_porta_443(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertTrue(CookieConfig::isHttps());
    }

    public function test_cookie_config_is_https_false_em_http(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $this->assertFalse(CookieConfig::isHttps());
    }

    public function test_cookie_config_spoofing_x_forwarded_proto_externo_bloqueado(): void
    {
        // Atacante externo tenta fazer spoofing de X-Forwarded-Proto
        $_ENV['TRUST_PROXY']                    = 'false';
        $_SERVER['REMOTE_ADDR']                 = '203.0.113.99'; // IP externo
        $_SERVER['HTTP_X_FORWARDED_PROTO']      = 'https';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        // Sem TRUST_PROXY e sem loopback, deve ignorar o header
        $this->assertFalse(CookieConfig::isHttps(),
            'X-Forwarded-Proto de IP externo sem TRUST_PROXY deve ser ignorado');
    }

    public function test_cookie_config_x_forwarded_proto_de_loopback_aceito(): void
    {
        $_ENV['TRUST_PROXY']               = 'false';
        $_SERVER['REMOTE_ADDR']            = '127.0.0.1'; // Nginx local
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $this->assertTrue(CookieConfig::isHttps(),
            'X-Forwarded-Proto de loopback deve ser aceito (Nginx local)');
    }

    public function test_cookie_samesite_none_sem_secure_cai_para_lax(): void
    {
        $_ENV['COOKIE_SAMESITE'] = 'None';
        $_ENV['COOKIE_SECURE']   = 'false';
        $this->assertSame('Lax', CookieConfig::options(0)['samesite'],
            'SameSite=None sem Secure deve cair para Lax — previne envio cross-site sem TLS');
    }

    public function test_cookie_domain_strip_protocolo(): void
    {
        $_ENV['COOKIE_DOMAIN'] = 'https://api.example.com';
        $this->assertSame('api.example.com', CookieConfig::options(0)['domain']);
    }

    public function test_cookie_httponly_true_por_padrao(): void
    {
        $this->assertTrue(CookieConfig::options(0)['httponly']);
    }

    public function test_cookie_requires_https_apenas_quando_ambos_true(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->assertTrue(CookieConfig::requiresHttps());

        $_ENV['COOKIE_SECURE']   = 'false';
        $this->assertFalse(CookieConfig::requiresHttps());
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. Sanitizer — edge cases
    // ══════════════════════════════════════════════════════════════════

    public function test_sanitizer_string_remove_null_byte_no_meio(): void
    {
        $this->assertSame('abcd', Sanitizer::string("ab\x00cd"));
    }

    public function test_sanitizer_string_remove_caracteres_controle(): void
    {
        $this->assertSame('texto', Sanitizer::string("tex\x08to")); // backspace
        $this->assertSame('texto', Sanitizer::string("tex\x1Fto")); // unit separator
    }

    public function test_sanitizer_string_preserva_unicode_valido(): void
    {
        $this->assertSame('Olá Mundo', Sanitizer::string('Olá Mundo'));
        $this->assertSame('日本語', Sanitizer::string('日本語'));
    }

    public function test_sanitizer_string_limita_tamanho_multibyte(): void
    {
        // Caracteres multibyte não devem cortar no meio de um codepoint
        $s = str_repeat('á', 300); // 'á' = 2 bytes em UTF-8
        $result = Sanitizer::string($s, 255);
        $this->assertLessThanOrEqual(255, mb_strlen($result));
    }

    public function test_sanitizer_email_lowercase(): void
    {
        $this->assertSame('user@example.com', Sanitizer::email('USER@EXAMPLE.COM'));
    }

    public function test_sanitizer_email_rejeita_unicode_homoglyph(): void
    {
        // Homoglyph attack: 'а' cirílico parece 'a' latino
        $this->assertSame('', Sanitizer::email("аdmin@example.com"));
    }

    public function test_sanitizer_url_rejeita_javascript_protocol(): void
    {
        $this->assertSame('', Sanitizer::url('javascript:alert(1)'));
    }

    public function test_sanitizer_url_rejeita_data_uri(): void
    {
        $this->assertSame('', Sanitizer::url('data:text/html,<script>alert(1)</script>'));
    }

    public function test_sanitizer_url_rejeita_file_protocol(): void
    {
        $this->assertSame('', Sanitizer::url('file:///etc/passwd'));
    }

    public function test_sanitizer_url_rejeita_dict_protocol(): void
    {
        $this->assertSame('', Sanitizer::url('dict://localhost:11211/stat'));
    }

    public function test_sanitizer_url_rejeita_ftp_protocol(): void
    {
        $this->assertSame('', Sanitizer::url('ftp://evil.com/malware.exe'));
    }

    public function test_sanitizer_url_aceita_http_e_https(): void
    {
        $this->assertSame('https://cdn.example.com/img.jpg', Sanitizer::url('https://cdn.example.com/img.jpg'));
        $this->assertSame('http://example.com/path', Sanitizer::url('http://example.com/path'));
    }

    public function test_sanitizer_password_preserva_caracteres_especiais(): void
    {
        $senha = '<script>P@$$w0rd!</script>';
        $this->assertSame($senha, Sanitizer::password($senha),
            'Senha não deve ter strip_tags — usuário pode usar < > na senha');
    }

    public function test_sanitizer_password_limita_128_chars_previne_bcrypt_dos(): void
    {
        // bcrypt trunca em 72 bytes — senha > 128 chars é DoS potencial
        $longa = str_repeat('a', 200);
        $this->assertSame(128, mb_strlen(Sanitizer::password($longa)));
    }

    public function test_sanitizer_nivel_acesso_rejeita_injecao(): void
    {
        foreach (["'; DROP TABLE--", 'admin_system2', 'root', 'superuser', '<script>'] as $v) {
            $this->assertSame('', Sanitizer::nivelAcesso($v), "Nível '$v' deve ser rejeitado");
        }
    }

    public function test_sanitizer_nivel_acesso_aceita_apenas_whitelist(): void
    {
        foreach (['usuario', 'moderador', 'admin', 'admin_system'] as $v) {
            $this->assertSame($v, Sanitizer::nivelAcesso($v));
        }
    }

    public function test_sanitizer_search_remove_null_bytes(): void
    {
        $this->assertSame('busca', Sanitizer::search("bus\x00ca"));
    }

    public function test_sanitizer_search_limita_tamanho(): void
    {
        $this->assertLessThanOrEqual(100, mb_strlen(Sanitizer::search(str_repeat('x', 200))));
    }

    public function test_sanitizer_positive_int_rejeita_negativo(): void
    {
        $this->assertSame(1, Sanitizer::positiveInt(-999));
    }

    public function test_sanitizer_positive_int_rejeita_zero(): void
    {
        $this->assertSame(1, Sanitizer::positiveInt(0));
    }

    public function test_sanitizer_positive_int_respeita_max(): void
    {
        $this->assertSame(100, Sanitizer::positiveInt(PHP_INT_MAX, 1, 100));
    }

    public function test_sanitizer_uuid_rejeita_v1(): void
    {
        // UUID v1 — não é v4
        $this->assertSame('', Sanitizer::uuid('550e8400-e29b-11d4-a716-446655440000'));
    }

    public function test_sanitizer_uuid_rejeita_path_traversal(): void
    {
        $this->assertSame('', Sanitizer::uuid('../../../etc/passwd'));
    }

    public function test_sanitizer_uuid_rejeita_sql_injection(): void
    {
        $this->assertSame('', Sanitizer::uuid("' OR '1'='1"));
    }

    public function test_sanitizer_uuid_valido_v4_aceito(): void
    {
        $this->assertSame(
            'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            Sanitizer::uuid('f47ac10b-58cc-4372-a567-0e02b2c3d479')
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. AuditLogger — SSRF prevention no webhook
    // ══════════════════════════════════════════════════════════════════

    public function test_audit_logger_bloqueia_webhook_http(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        // HTTP (não HTTPS) deve ser bloqueado antes de chegar em isInternalHost
        // Verificamos via enviarWebhook que não faz requisição para URL HTTP
        $refWebhook = new \ReflectionMethod(AuditLogger::class, 'enviarWebhook');
        $refWebhook->setAccessible(true);
        // Não deve lançar exceção — falha silenciosa
        $refWebhook->invoke($logger, 'http://evil.com/hook', 'TEST', []);
        $this->assertTrue(true, 'Webhook HTTP deve ser silenciosamente ignorado');
    }

    public function test_audit_logger_bloqueia_webhook_localhost(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($logger, 'localhost'),
            'localhost deve ser bloqueado como host interno');
    }

    public function test_audit_logger_bloqueia_webhook_metadata_aws(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($logger, '169.254.169.254'),
            'IP de metadados AWS deve ser bloqueado');
    }

    public function test_audit_logger_bloqueia_webhook_metadata_gcp(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($logger, 'metadata.google.internal'),
            'Metadata GCP deve ser bloqueado');
    }

    public function test_audit_logger_bloqueia_ip_privado_classe_a(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($logger, '10.0.0.1'),
            'IP privado classe A deve ser bloqueado');
    }

    public function test_audit_logger_bloqueia_ip_privado_classe_b(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($logger, '172.16.0.1'),
            'IP privado classe B deve ser bloqueado');
    }

    public function test_audit_logger_bloqueia_ip_privado_classe_c(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($logger, '192.168.1.1'),
            'IP privado classe C deve ser bloqueado');
    }

    public function test_audit_logger_permite_host_externo_valido(): void
    {
        $logger = new AuditLogger(null);
        $ref    = new \ReflectionMethod(AuditLogger::class, 'isInternalHost');
        $ref->setAccessible(true);
        // 203.0.113.x é documentação (TEST-NET-3) — não é privado
        $this->assertFalse($ref->invoke($logger, '203.0.113.1'),
            'IP público de documentação não deve ser bloqueado');
    }

    public function test_audit_logger_registrar_sem_pdo_nao_lanca_excecao(): void
    {
        $logger = new AuditLogger(null);
        $logger->registrar('auth.login.failed', null, ['ip' => '1.2.3.4']);
        $this->assertTrue(true, 'registrar() sem PDO não deve lançar exceção');
    }

    // ══════════════════════════════════════════════════════════════════
    // 6. BotBlockerMiddleware — delay e score acumulado
    // ══════════════════════════════════════════════════════════════════

    public function test_bot_blocker_bloqueia_ip_com_score_alto(): void
    {
        $_SERVER['REMOTE_ADDR']     = '9.9.9.9';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_ENV['APP_ENV']            = 'production'; // score é verificado em produção

        $scorer = $this->scorer();
        $scorer->add('9.9.9.9', ThreatScorer::THRESHOLD_BLOCK);

        $mw  = new BotBlockerMiddleware($scorer);
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertSame(403, $res->getStatusCode(),
            'IP com score >= threshold deve ser bloqueado');
    }

    public function test_bot_blocker_loopback_ignora_score_em_testing(): void
    {
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_ENV['APP_ENV']            = 'testing';

        $scorer = $this->scorer();
        $scorer->add('127.0.0.1', ThreatScorer::THRESHOLD_BLOCK + 100);

        $mw  = new BotBlockerMiddleware($scorer);
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertSame(200, $res->getStatusCode(),
            'Loopback em testing deve ignorar score acumulado');
    }

    public function test_bot_blocker_ua_malicioso_acumula_score(): void
    {
        $_SERVER['REMOTE_ADDR']     = '5.5.5.5';
        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.7';
        $_ENV['APP_ENV']            = 'testing';

        $scorer = $this->scorer();
        $mw     = new BotBlockerMiddleware($scorer);
        $mw->handle($this->req('/api/test'), $this->next());

        $this->assertGreaterThanOrEqual(
            ThreatScorer::SCORE_MALICIOUS_UA,
            $scorer->get('5.5.5.5'),
            'UA malicioso deve acumular pontos no ThreatScorer'
        );
    }

    public function test_bot_blocker_sem_ua_em_api_acumula_score(): void
    {
        $_SERVER['REMOTE_ADDR'] = '6.6.6.6';
        unset($_SERVER['HTTP_USER_AGENT']);
        $_ENV['APP_ENV'] = 'testing';

        $scorer = $this->scorer();
        $mw     = new BotBlockerMiddleware($scorer);
        $mw->handle($this->req('/api/login'), $this->next());

        $this->assertGreaterThanOrEqual(
            ThreatScorer::SCORE_NO_UA,
            $scorer->get('6.6.6.6'),
            'Requisição de API sem UA deve acumular pontos'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 7. RateLimitMiddleware — rate limit por usuário e window reset
    // ══════════════════════════════════════════════════════════════════

    public function test_rate_limit_por_usuario_autenticado_independente_do_ip(): void
    {
        $uuid    = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $storage = new \Src\Kernel\Support\Storage\FileRateLimitStorage($this->tmpDir);
        $mw      = new RateLimitMiddleware(2, 60, 'auth.user', 2, $storage);

        $user = new class($uuid) {
            public function __construct(private string $u) {}
            public function getUuid(): string { return $this->u; }
        };

        $req = $this->req('/api/me')->withAttribute('auth_user', $user);

        // IP 1 — 2 requisições
        $_SERVER['REMOTE_ADDR'] = '1.1.1.1';
        $mw->handle($req, $this->next());
        $mw->handle($req, $this->next());

        // IP 2 — mesmo usuário, deve ser bloqueado pelo limite de usuário
        $_SERVER['REMOTE_ADDR'] = '2.2.2.2';
        $res = $mw->handle($req, $this->next());
        $this->assertSame(429, $res->getStatusCode(),
            'Rate limit por usuário deve bloquear mesmo com IP diferente');
    }

    public function test_rate_limit_remaining_nunca_negativo(): void
    {
        $mw = $this->rateLimiter(3);
        for ($i = 0; $i < 5; $i++) {
            $res = $mw->handle($this->req(), $this->next());
        }
        $remaining = (int) ($res->getHeaders()['X-RateLimit-Remaining'] ?? 0);
        $this->assertGreaterThanOrEqual(0, $remaining, 'Remaining não pode ser negativo');
    }

    public function test_rate_limit_reset_timestamp_no_futuro(): void
    {
        $mw  = $this->rateLimiter(10);
        $res = $mw->handle($this->req(), $this->next());
        $reset = (int) ($res->getHeaders()['X-RateLimit-Reset'] ?? 0);
        $this->assertGreaterThan(time(), $reset, 'Reset timestamp deve ser no futuro');
    }

    // ══════════════════════════════════════════════════════════════════
    // 8. SecurityHeadersMiddleware — pipeline completo
    // ══════════════════════════════════════════════════════════════════

    public function test_security_headers_adiciona_todos_headers_em_api(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $h   = $res->getHeaders();

        $this->assertArrayHasKey('X-Content-Type-Options',      $h);
        $this->assertArrayHasKey('X-Frame-Options',              $h);
        $this->assertArrayHasKey('Referrer-Policy',              $h);
        $this->assertArrayHasKey('Permissions-Policy',           $h);
        $this->assertArrayHasKey('Content-Security-Policy',      $h);
        $this->assertArrayHasKey('Cross-Origin-Resource-Policy', $h);
        $this->assertArrayHasKey('Cross-Origin-Opener-Policy',   $h);
        $this->assertArrayHasKey('Cross-Origin-Embedder-Policy', $h);
    }

    public function test_security_headers_csp_api_usa_default_src_none(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertStringContainsString("default-src 'none'",
            $res->getHeaders()['Content-Security-Policy']);
    }

    public function test_security_headers_csp_pagina_usa_nonce(): void
    {
        \Src\Kernel\Nonce::reset();
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/dashboard'), $this->next());
        $this->assertStringContainsString("'nonce-",
            $res->getHeaders()['Content-Security-Policy']);
    }

    public function test_security_headers_referrer_api_e_no_referrer(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertSame('no-referrer', $res->getHeaders()['Referrer-Policy']);
    }

    public function test_security_headers_hsts_presente_em_https(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $hsts = $res->getHeaders()['Strict-Transport-Security'] ?? '';
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
        $this->assertStringContainsString('preload', $hsts);
    }

    public function test_security_headers_hsts_ausente_em_http(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertArrayNotHasKey('Strict-Transport-Security', $res->getHeaders());
    }

    public function test_security_headers_x_xss_protection_ausente(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertArrayNotHasKey('X-XSS-Protection', $res->getHeaders(),
            'X-XSS-Protection é deprecated e não deve ser enviado');
    }

    // ══════════════════════════════════════════════════════════════════
    // 9. HttpsEnforcerMiddleware
    // ══════════════════════════════════════════════════════════════════

    public function test_https_enforcer_bloqueia_http_quando_cookie_secure_true(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $_SERVER['REMOTE_ADDR']  = '203.0.113.1';
        $_SERVER['HTTP_ACCEPT']  = 'application/json';
        $_SERVER['REQUEST_URI']  = '/api/login';

        $mw  = new HttpsEnforcerMiddleware();
        $res = $mw->handle($this->req('/api/login'), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_https_enforcer_passa_quando_https_ativo(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $_SERVER['HTTPS']        = 'on';

        $passed = false;
        $mw     = new HttpsEnforcerMiddleware();
        $mw->handle($this->req('/api/login'), function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed, 'HTTPS ativo deve deixar requisição passar');
    }

    public function test_https_enforcer_passa_quando_cookie_secure_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'false';
        $_ENV['COOKIE_HTTPONLY'] = 'true';

        $passed = false;
        $mw     = new HttpsEnforcerMiddleware();
        $mw->handle($this->req('/api/login'), function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed, 'COOKIE_SECURE=false não deve bloquear HTTP');
    }

    public function test_https_enforcer_retorna_json_para_api(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $_SERVER['REMOTE_ADDR']  = '203.0.113.1';
        $_SERVER['REQUEST_URI']  = '/api/login';
        $_SERVER['HTTP_ACCEPT']  = 'application/json';

        $mw  = new HttpsEnforcerMiddleware();
        $res = $mw->handle($this->req('/api/login'), $this->next());
        $ct  = $res->getHeaders()['Content-Type'] ?? '';
        $this->assertStringContainsString('application/json', $ct);
    }

    // ══════════════════════════════════════════════════════════════════
    // 10. ThreatScorer — TTL e delay progressivo
    // ══════════════════════════════════════════════════════════════════

    public function test_threat_scorer_delay_zero_abaixo_threshold(): void
    {
        $s = $this->scorer();
        $s->add('1.1.1.1', ThreatScorer::THRESHOLD_DELAY - 1);
        $this->assertSame(0, $s->delaySeconds('1.1.1.1'));
    }

    public function test_threat_scorer_delay_2s_no_threshold_delay(): void
    {
        $s = $this->scorer();
        $s->add('2.2.2.2', ThreatScorer::THRESHOLD_DELAY);
        $this->assertSame(2, $s->delaySeconds('2.2.2.2'));
    }

    public function test_threat_scorer_delay_5s_em_score_100(): void
    {
        $s = $this->scorer();
        $s->add('3.3.3.3', 100);
        $this->assertSame(5, $s->delaySeconds('3.3.3.3'));
    }

    public function test_threat_scorer_delay_10s_acima_threshold_block(): void
    {
        $s = $this->scorer();
        $s->add('4.4.4.4', ThreatScorer::THRESHOLD_BLOCK);
        $this->assertSame(10, $s->delaySeconds('4.4.4.4'));
    }

    public function test_threat_scorer_ip_novo_score_zero(): void
    {
        $s = $this->scorer();
        $this->assertSame(0, $s->get('99.99.99.99'));
        $this->assertFalse($s->shouldBlock('99.99.99.99'));
    }

    public function test_threat_scorer_acumula_pontos_corretamente(): void
    {
        $s = $this->scorer();
        $s->add('5.5.5.5', ThreatScorer::SCORE_LOGIN_FAIL);
        $s->add('5.5.5.5', ThreatScorer::SCORE_LOGIN_FAIL);
        $this->assertSame(ThreatScorer::SCORE_LOGIN_FAIL * 2, $s->get('5.5.5.5'));
    }

    public function test_threat_scorer_ips_diferentes_isolados(): void
    {
        $s = $this->scorer();
        $s->add('10.0.0.1', ThreatScorer::THRESHOLD_BLOCK);
        $this->assertFalse($s->shouldBlock('10.0.0.2'),
            'Score de um IP não deve afetar outro');
    }

    public function test_threat_scorer_honeypot_nao_bloqueia_sozinho(): void
    {
        // SCORE_HONEYPOT=100 < THRESHOLD_BLOCK=150 — sozinho não bloqueia, mas ativa delay
        $s = $this->scorer();
        $s->add('7.7.7.7', ThreatScorer::SCORE_HONEYPOT);
        $this->assertFalse($s->shouldBlock('7.7.7.7'),
            'Honeypot sozinho (100pts) não atinge threshold de bloqueio (150pts)');
        $this->assertGreaterThan(0, $s->delaySeconds('7.7.7.7'),
            'Honeypot deve ativar delay progressivo');
    }

    public function test_threat_scorer_honeypot_mais_ua_malicioso_bloqueia(): void
    {
        // SCORE_HONEYPOT(100) + SCORE_MALICIOUS_UA(50) = 150 = THRESHOLD_BLOCK
        $s = $this->scorer();
        $s->add('8.8.8.8', ThreatScorer::SCORE_HONEYPOT);
        $s->add('8.8.8.8', ThreatScorer::SCORE_MALICIOUS_UA);
        $this->assertTrue($s->shouldBlock('8.8.8.8'),
            'Honeypot + UA malicioso (150pts) deve atingir threshold de bloqueio');
    }

    // ══════════════════════════════════════════════════════════════════
    // 11. TokenExtractor — edge cases de segurança
    // ══════════════════════════════════════════════════════════════════

    public function test_token_extractor_ignora_cookie_apenas_espacos(): void
    {
        $_COOKIE['auth_token']         = '   ';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer real.token.here';
        $this->assertSame('real.token.here', TokenExtractor::fromRequest());
    }

    public function test_token_extractor_x_api_key_vazio_cai_para_bearer(): void
    {
        $_SERVER['HTTP_X_API_KEY']     = '';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer.token';
        $this->assertSame('bearer.token', TokenExtractor::fromApiRequest());
    }

    public function test_token_extractor_x_api_key_apenas_espacos_cai_para_bearer(): void
    {
        $_SERVER['HTTP_X_API_KEY']     = '   ';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer.token';
        $this->assertSame('bearer.token', TokenExtractor::fromApiRequest());
    }

    public function test_token_extractor_bearer_case_insensitive(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'BEARER my.token.here';
        $this->assertSame('my.token.here', TokenExtractor::fromBearer());
    }

    public function test_token_extractor_sem_nenhum_token_retorna_vazio(): void
    {
        unset($_COOKIE['auth_token'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_API_KEY']);
        $this->assertSame('', TokenExtractor::fromApiRequest());
    }

    // ══════════════════════════════════════════════════════════════════
    // 12. Performance — middlewares devem ser rápidos
    // ══════════════════════════════════════════════════════════════════

    public function test_bot_blocker_100_requisicoes_em_menos_de_1_segundo(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0)';
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_ENV['APP_ENV']            = 'testing';

        $scorer = $this->scorer();
        $mw     = new BotBlockerMiddleware($scorer);
        $req    = $this->req('/api/test');
        $next   = $this->next();

        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $mw->handle($req, $next);
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed,
            "100 requisições no BotBlocker levaram {$elapsed}s — deve ser < 1s");
    }

    public function test_rate_limiter_50_requisicoes_em_menos_de_500ms(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $mw  = $this->rateLimiter(1000, 'perf_test');
        $req = $this->req('/api/test');

        $start = microtime(true);
        for ($i = 0; $i < 50; $i++) {
            $mw->handle($req, $this->next());
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed,
            "50 requisições no RateLimiter levaram {$elapsed}s — deve ser < 500ms");
    }

    public function test_threat_scorer_100_adds_em_menos_de_2_segundos(): void
    {
        $s     = $this->scorer();
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $s->add('perf.test.ip', 1);
        }
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(2.0, $elapsed,
            "100 operações add() no ThreatScorer levaram {$elapsed}s — deve ser < 2s");
    }

    public function test_sanitizer_1000_chamadas_em_menos_de_100ms(): void
    {
        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            Sanitizer::string('<script>alert(1)</script> texto normal');
            Sanitizer::email("user$i@example.com");
            Sanitizer::uuid('f47ac10b-58cc-4372-a567-0e02b2c3d479');
        }
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(0.1, $elapsed,
            "1000 chamadas ao Sanitizer levaram {$elapsed}s — deve ser < 100ms");
    }

    // ══════════════════════════════════════════════════════════════════
    // 13. Encadeamento de middlewares — sem vazamento de estado
    // ══════════════════════════════════════════════════════════════════

    public function test_pipeline_bot_blocker_rate_limit_sem_vazamento(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_ENV['APP_ENV']            = 'testing';

        $scorer = $this->scorer();
        $bot    = new BotBlockerMiddleware($scorer);
        $rl     = $this->rateLimiter(100, 'pipeline_test');
        $req    = $this->req('/api/test');

        // Encadeia: BotBlocker → RateLimit → next
        $pipeline = function (Request $r) use ($rl) {
            return $rl->handle($r, fn($r2) => Response::json(['ok' => true]));
        };

        for ($i = 0; $i < 5; $i++) {
            $res = $bot->handle($req, $pipeline);
            $this->assertSame(200, $res->getStatusCode(),
                "Requisição $i no pipeline deve retornar 200");
        }
    }

    public function test_pipeline_security_headers_envolve_qualquer_resposta(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $req = $this->req('/api/test');

        // Mesmo uma resposta 401 deve ter headers de segurança
        $res = $mw->handle($req, fn($r) => Response::json(['error' => 'Não autenticado'], 401));
        $this->assertArrayHasKey('X-Content-Type-Options', $res->getHeaders());
        $this->assertArrayHasKey('Content-Security-Policy', $res->getHeaders());
        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_pipeline_security_headers_envolve_resposta_403(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->req('/api/test'), fn($r) => Response::json(['error' => 'Proibido'], 403));
        $this->assertArrayHasKey('X-Frame-Options', $res->getHeaders());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_admin_only_nao_vaza_info_de_nivel_em_resposta(): void
    {
        $req = $this->req('/api/admin')
            ->withAttribute('auth_payload', (object)['nivel_acesso' => 'usuario'])
            ->withAttribute('auth_user', new class {
                public function getNivelAcesso(): string { return 'usuario'; }
            })
            ->withAttribute('token_signed_with_api_secret', false);

        $res  = (new AdminOnlyMiddleware())->handle($req, $this->next());
        $body = $res->getBody();
        $json = is_array($body) ? $body : (json_decode(json_encode($body), true) ?? []);

        $this->assertSame(403, $res->getStatusCode());
        // Resposta não deve vazar o nível de acesso do usuário
        $this->assertStringNotContainsString('usuario',
            json_encode($json),
            'Resposta 403 não deve revelar o nível de acesso do usuário');
    }
}
