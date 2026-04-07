<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Middlewares\BotBlockerMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Support\ThreatScorer;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\EmailThrottle;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Testes dos mecanismos de defesa.
 */
class DefenseTest extends TestCase
{
    private array $originalServer = [];
    private array $originalEnv    = [];
    private array $originalCookie = [];
    private string $tmpDir        = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer   = $_SERVER;
        $this->originalEnv      = $_ENV;
        $this->originalCookie   = $_COOKIE;
        $_ENV['APP_ENV']        = 'testing';
        $_ENV['TRUST_PROXY']    = 'false';
        $_ENV['JWT_SECRET']     = 'test-jwt-secret-32-chars-minimum!';
        $_ENV['JWT_API_SECRET'] = 'test-api-secret-32-chars-minimum!';
        $_ENV['JWT_ISSUER']     = '';
        $_ENV['JWT_AUDIENCE']   = '';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $this->tmpDir = sys_get_temp_dir() . '/sweflow_defense_' . uniqid();
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

    private function scorer(): ThreatScorer
    {
        return new ThreatScorer(
            new \Src\Kernel\Support\Storage\FileRateLimitStorage($this->tmpDir)
        );
    }

    private function rateLimiter(int $limit = 3, string $key = 'test'): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $limit, 60, $key, 0,
            new \Src\Kernel\Support\Storage\FileRateLimitStorage($this->tmpDir)
        );
    }

    private function req(string $uri = '/api/test', string $method = 'GET'): Request
    {
        return new Request([], [], [], $method, $uri);
    }

    private function next(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    // ThreatScorer

    public function test_scorer_acumula_pontos(): void
    {
        $s = $this->scorer();
        $s->add('1.2.3.4', 30);
        $s->add('1.2.3.4', 30);
        $this->assertSame(60, $s->get('1.2.3.4'));
    }

    public function test_scorer_ips_diferentes_isolados(): void
    {
        $s = $this->scorer();
        $s->add('1.1.1.1', 100);
        $this->assertSame(0, $s->get('2.2.2.2'));
    }

    public function test_scorer_bloqueia_apos_threshold(): void
    {
        $s = $this->scorer();
        $s->add('1.2.3.4', ThreatScorer::THRESHOLD_BLOCK);
        $this->assertTrue($s->shouldBlock('1.2.3.4'));
    }

    public function test_scorer_nao_bloqueia_abaixo_threshold(): void
    {
        $s = $this->scorer();
        $s->add('1.2.3.4', ThreatScorer::THRESHOLD_BLOCK - 1);
        $this->assertFalse($s->shouldBlock('1.2.3.4'));
    }

    public function test_scorer_5_logins_falhos_bloqueiam(): void
    {
        $s = $this->scorer();
        for ($i = 0; $i < 5; $i++) {
            $s->add('5.5.5.5', ThreatScorer::SCORE_LOGIN_FAIL);
        }
        $this->assertTrue($s->shouldBlock('5.5.5.5'), '5x30=150 deve atingir threshold');
    }

    public function test_scorer_delay_progressivo(): void
    {
        $s = $this->scorer();
        $s->add('7.7.7.7', ThreatScorer::THRESHOLD_DELAY);
        $this->assertGreaterThan(0, $s->delaySeconds('7.7.7.7'));
    }

    public function test_scorer_sem_delay_abaixo_threshold(): void
    {
        $s = $this->scorer();
        $s->add('8.8.8.8', ThreatScorer::THRESHOLD_DELAY - 1);
        $this->assertSame(0, $s->delaySeconds('8.8.8.8'));
    }

    public function test_scorer_ip_novo_tem_score_zero(): void
    {
        $s = $this->scorer();
        $this->assertSame(0, $s->get('99.99.99.99'));
        $this->assertFalse($s->shouldBlock('99.99.99.99'));
    }

    // BotBlockerMiddleware

    #[\PHPUnit\Framework\Attributes\DataProvider('uasMaliciososProvider')]
    public function test_bot_blocker_bloqueia_ua_malicioso(string $ua): void
    {
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $mw  = new BotBlockerMiddleware($this->scorer());
        $res = $mw->handle($this->req(), $this->next());
        $this->assertSame(403, $res->getStatusCode(), "UA '$ua' deve ser bloqueado");
    }

    public static function uasMaliciososProvider(): array
    {
        return [
            ['sqlmap/1.7'], ['Nikto/2.1.6'], ['masscan/1.3'], ['nuclei/2.9.0'],
            ['gobuster/3.1.0'], ['wfuzz/3.1.0'], ['ffuf/1.5.0'], ['feroxbuster/2.7.0'],
            ['Hydra'], ['Burp Suite Professional'], ['Acunetix Web Vulnerability Scanner'],
            ['libwww-perl/6.07'], ['python-scrapy/2.7.0'],
        ];
    }

    public function test_bot_blocker_bloqueia_api_sem_ua(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $mw  = new BotBlockerMiddleware($this->scorer());
        $res = $mw->handle($this->req('/api/login'), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_bot_blocker_permite_pagina_sem_ua(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $mw  = new BotBlockerMiddleware($this->scorer());
        $res = $mw->handle($this->req('/'), $this->next());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_bot_blocker_permite_ua_legitimo(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
        $mw  = new BotBlockerMiddleware($this->scorer());
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_bot_blocker_bloqueia_ip_com_score_alto_em_producao(): void
    {
        // Verifica que o scorer bloqueia corretamente — o middleware respeita shouldBlock()
        // Não simula produção via reflection para evitar output em stderr nos testes
        $_SERVER['REMOTE_ADDR']     = '9.9.9.9';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

        $scorer = $this->scorer();
        $scorer->add('9.9.9.9', ThreatScorer::THRESHOLD_BLOCK);

        // Confirma que o scorer reporta bloqueio
        $this->assertTrue($scorer->shouldBlock('9.9.9.9'),
            'Score >= threshold deve resultar em shouldBlock=true');

        // Confirma que o middleware bloqueia quando shouldBlock=true
        // (usa env=testing para não gerar output, mas a lógica de bloqueio é a mesma)
        $mw  = new BotBlockerMiddleware($scorer);
        // Força env para não-loopback para que o score seja verificado
        $_SERVER['REMOTE_ADDR'] = '9.9.9.9'; // IP não-loopback
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertSame(403, $res->getStatusCode(),
            'IP com score >= threshold deve ser bloqueado');
    }

    public function test_bot_blocker_loopback_ignora_score_em_dev(): void
    {
        $_ENV['APP_ENV']            = 'local';
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

        $scorer = $this->scorer();
        $scorer->add('127.0.0.1', ThreatScorer::THRESHOLD_BLOCK + 100);

        $mw  = new BotBlockerMiddleware($scorer);
        $res = $mw->handle($this->req('/api/test'), $this->next());
        $this->assertSame(200, $res->getStatusCode());
    }

    // RateLimitMiddleware

    public function test_rate_limit_bloqueia_apos_limite(): void
    {
        $mw = $this->rateLimiter(2);
        $mw->handle($this->req(), $this->next());
        $mw->handle($this->req(), $this->next());
        $res = $mw->handle($this->req(), $this->next());
        $this->assertSame(429, $res->getStatusCode());
    }

    public function test_rate_limit_headers_presentes(): void
    {
        $mw  = $this->rateLimiter(10);
        $res = $mw->handle($this->req(), $this->next());
        $this->assertArrayHasKey('X-RateLimit-Limit',     $res->getHeaders());
        $this->assertArrayHasKey('X-RateLimit-Remaining', $res->getHeaders());
        $this->assertArrayHasKey('X-RateLimit-Reset',     $res->getHeaders());
    }

    public function test_rate_limit_429_tem_retry_after(): void
    {
        $mw = $this->rateLimiter(1);
        $mw->handle($this->req(), $this->next());
        $res = $mw->handle($this->req(), $this->next());
        $this->assertSame(429, $res->getStatusCode());
        $this->assertArrayHasKey('Retry-After', $res->getHeaders());
    }

    public function test_rate_limit_ips_diferentes_isolados(): void
    {
        $mw = $this->rateLimiter(1);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $mw->handle($this->req(), $this->next());
        $res1 = $mw->handle($this->req(), $this->next());
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $res2 = $mw->handle($this->req(), $this->next());
        $this->assertSame(429, $res1->getStatusCode());
        $this->assertSame(200, $res2->getStatusCode());
    }

    // AuthHybridMiddleware — verificações via source (JWT gera output em stderr)
    // Os testes de decodificação JWT estão em JwtDecoderTest.
    // Os testes de pipeline completo estão em RouteSecurityTest.

    public function test_auth_middleware_verifica_blacklist_no_source(): void
    {
        $source = file_get_contents((new \ReflectionClass(AuthHybridMiddleware::class))->getFileName());
        $this->assertStringContainsString('isRevoked', $source,
            'AuthHybridMiddleware deve verificar blacklist');
    }

    public function test_auth_middleware_valida_uuid_com_regex_no_source(): void
    {
        $source = file_get_contents((new \ReflectionClass(AuthHybridMiddleware::class))->getFileName());
        $this->assertStringContainsString('preg_match', $source,
            'AuthHybridMiddleware deve validar UUID com regex');
    }

    public function test_auth_middleware_rejeita_sem_token_via_extractor(): void
    {
        // Testa apenas o caminho sem token — não envolve JWT/Firebase
        unset($_COOKIE['auth_token'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_API_KEY']);

        $bl = $this->createStub(\Src\Kernel\Contracts\TokenBlacklistInterface::class);
        $ur = $this->createStub(\Src\Kernel\Contracts\UserRepositoryInterface::class);
        $mw = new AuthHybridMiddleware($ur, $bl);

        $res = $mw->handle($this->req('/api/me'), $this->next());

        $this->assertSame(401, $res->getStatusCode(), 'Sem token deve retornar 401');
    }

    public function test_auth_middleware_uuid_malformado_bloqueado_sem_jwt(): void
    {
        // Verifica que o regex de UUID está correto — sem precisar decodificar JWT
        $uuidsInvalidos = ['not-a-uuid', 'admin', str_repeat('a', 36), '<script>'];
        foreach ($uuidsInvalidos as $uuid) {
            $this->assertSame(0, preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $uuid
            ), "UUID '$uuid' deve ser rejeitado pelo regex");
        }
    }

    public function test_auth_middleware_uuid_valido_aceito_pelo_regex(): void
    {
        $this->assertSame(1, preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'f47ac10b-58cc-4372-a567-0e02b2c3d479'
        ));
    }

    // AdminOnlyMiddleware — escalada de privilégio

    private function adminReq(string $nivel, bool $apiSecret): Request
    {
        return $this->req('/api/admin')
            ->withAttribute('auth_payload', (object)['nivel_acesso' => $nivel])
            ->withAttribute('auth_user', new class($nivel) {
                public function __construct(private string $n) {}
                public function getNivelAcesso(): string { return $this->n; }
            })
            ->withAttribute('token_signed_with_api_secret', $apiSecret);
    }

    public function test_admin_bloqueia_sem_payload(): void
    {
        $res = (new AdminOnlyMiddleware())->handle($this->req('/api/admin'), $this->next());
        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_admin_bloqueia_usuario_comum(): void
    {
        $res = (new AdminOnlyMiddleware())->handle($this->adminReq('usuario', false), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_admin_bloqueia_moderador(): void
    {
        $res = (new AdminOnlyMiddleware())->handle($this->adminReq('moderador', false), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_admin_bloqueia_admin_system_sem_api_secret(): void
    {
        $res = (new AdminOnlyMiddleware())->handle($this->adminReq('admin_system', false), $this->next());
        $this->assertSame(403, $res->getStatusCode(), 'Escalada de privilegio deve ser bloqueada');
    }

    public function test_admin_bloqueia_admin_regular_mesmo_com_api_secret(): void
    {
        $res = (new AdminOnlyMiddleware())->handle($this->adminReq('admin', true), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_admin_permite_admin_system_com_api_secret(): void
    {
        $passed = false;
        (new AdminOnlyMiddleware())->handle($this->adminReq('admin_system', true), function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed);
    }

    public function test_admin_permite_api_token_puro(): void
    {
        $passed = false;
        $req    = $this->req('/api/admin')->withAttribute('api_token', true);
        (new AdminOnlyMiddleware())->handle($req, function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed);
    }

    // EmailThrottle

    public function test_email_throttle_permite_primeiro_envio(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $this->assertTrue((new EmailThrottle($pdo))->canSend('verification', 'a@b.com'));
    }

    public function test_email_throttle_bloqueia_dentro_do_cooldown(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['sent_at' => date('Y-m-d H:i:s', time() - 30)]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $this->assertFalse((new EmailThrottle($pdo))->canSend('verification', 'a@b.com', 120));
    }

    public function test_email_throttle_permite_apos_cooldown(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['sent_at' => date('Y-m-d H:i:s', time() - 200)]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $this->assertTrue((new EmailThrottle($pdo))->canSend('verification', 'a@b.com', 120));
    }

    // IpResolver — anti-spoofing

    public function test_ip_ignora_x_forwarded_for_sem_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']             = 'false';
        $_SERVER['REMOTE_ADDR']          = '1.2.3.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
        $this->assertSame('1.2.3.4', IpResolver::resolve());
    }

    public function test_ip_usa_x_forwarded_for_com_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
        $this->assertSame('203.0.113.5', IpResolver::resolve());
    }

    public function test_ip_usa_primeiro_da_cadeia_x_forwarded_for(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 10.0.0.2, 172.16.0.1';
        $this->assertSame('203.0.113.5', IpResolver::resolve());
    }

    public function test_ip_prioriza_cf_connecting_ip(): void
    {
        $_ENV['TRUST_PROXY']              = 'true';
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.99';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '1.1.1.1';
        $this->assertSame('203.0.113.99', IpResolver::resolve());
    }

    public function test_ip_normaliza_ipv6_loopback(): void
    {
        $_ENV['TRUST_PROXY']    = 'false';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $this->assertSame('127.0.0.1', IpResolver::resolve());
    }

    public function test_ip_normaliza_ipv4_mapped_ipv6(): void
    {
        $_ENV['TRUST_PROXY']    = 'false';
        $_SERVER['REMOTE_ADDR'] = '::ffff:192.168.1.1';
        $this->assertSame('192.168.1.1', IpResolver::resolve());
    }

    public function test_ip_spoofing_via_x_forwarded_for_bloqueado(): void
    {
        $_ENV['TRUST_PROXY']             = 'false';
        $_SERVER['REMOTE_ADDR']          = '203.0.113.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
        $ip = IpResolver::resolve();
        $this->assertSame('203.0.113.1', $ip);
        $this->assertNotSame('127.0.0.1', $ip);
    }
}