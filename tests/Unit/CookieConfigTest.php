<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Support\CookieConfig;

class CookieConfigTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Salva e limpa as vars relevantes
        foreach (['COOKIE_SECURE', 'COOKIE_HTTPONLY', 'COOKIE_SAMESITE', 'COOKIE_DOMAIN'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        // Restaura
        foreach ($this->originalEnv as $key => $val) {
            if ($val === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $val;
            }
        }
        parent::tearDown();
    }

    public function test_defaults_sem_env(): void
    {
        $opts = CookieConfig::options(0);
        $this->assertFalse($opts['secure'],   'secure deve ser false por padrão');
        $this->assertTrue($opts['httponly'],  'httponly deve ser true por padrão');
        $this->assertSame('Lax', $opts['samesite']);
        $this->assertSame('/', $opts['path']);
        $this->assertArrayNotHasKey('domain', $opts);
    }

    public function test_cookie_secure_true(): void
    {
        $_ENV['COOKIE_SECURE'] = 'true';
        $opts = CookieConfig::options(0);
        $this->assertTrue($opts['secure']);
    }

    public function test_cookie_secure_false(): void
    {
        $_ENV['COOKIE_SECURE'] = 'false';
        $opts = CookieConfig::options(0);
        $this->assertFalse($opts['secure']);
    }

    public function test_cookie_httponly_false(): void
    {
        $_ENV['COOKIE_HTTPONLY'] = 'false';
        $opts = CookieConfig::options(0);
        $this->assertFalse($opts['httponly']);
    }

    public function test_samesite_strict(): void
    {
        $_ENV['COOKIE_SAMESITE'] = 'Strict';
        $opts = CookieConfig::options(0);
        $this->assertSame('Strict', $opts['samesite']);
    }

    public function test_samesite_none_com_secure_true(): void
    {
        $_ENV['COOKIE_SAMESITE'] = 'None';
        $_ENV['COOKIE_SECURE']   = 'true';
        $opts = CookieConfig::options(0);
        $this->assertSame('None', $opts['samesite']);
        $this->assertTrue($opts['secure']);
    }

    public function test_samesite_none_sem_secure_cai_para_lax(): void
    {
        $_ENV['COOKIE_SAMESITE'] = 'None';
        $_ENV['COOKIE_SECURE']   = 'false';
        $opts = CookieConfig::options(0);
        $this->assertSame('Lax', $opts['samesite'], 'SameSite=None sem Secure deve cair para Lax');
    }

    public function test_samesite_invalido_cai_para_lax(): void
    {
        $_ENV['COOKIE_SAMESITE'] = 'Invalid';
        $opts = CookieConfig::options(0);
        $this->assertSame('Lax', $opts['samesite']);
    }

    public function test_domain_incluido_quando_definido(): void
    {
        $_ENV['COOKIE_DOMAIN'] = 'api.typper.shop';
        $opts = CookieConfig::options(0);
        $this->assertArrayHasKey('domain', $opts);
        $this->assertSame('api.typper.shop', $opts['domain']);
    }

    public function test_domain_remove_protocolo(): void
    {
        $_ENV['COOKIE_DOMAIN'] = 'https://api.typper.shop';
        $opts = CookieConfig::options(0);
        $this->assertSame('api.typper.shop', $opts['domain']);
    }

    public function test_domain_vazio_nao_incluido(): void
    {
        $_ENV['COOKIE_DOMAIN'] = '';
        $opts = CookieConfig::options(0);
        $this->assertArrayNotHasKey('domain', $opts);
    }

    public function test_expires_e_path_customizados(): void
    {
        $expires = time() + 3600;
        $opts = CookieConfig::options($expires, '/api');
        $this->assertSame($expires, $opts['expires']);
        $this->assertSame('/api', $opts['path']);
    }

    public function test_configuracao_producao_do_env(): void
    {
        // Simula exatamente o que está no .env de produção
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $_ENV['COOKIE_SAMESITE'] = 'Lax';
        $_ENV['COOKIE_DOMAIN']   = '';

        $opts = CookieConfig::options(time() + 3600);
        $this->assertTrue($opts['secure']);
        $this->assertTrue($opts['httponly']);
        $this->assertSame('Lax', $opts['samesite']);
        $this->assertArrayNotHasKey('domain', $opts);
    }

    // ── requiresHttps ────────────────────────────────────────────────

    public function test_requires_https_quando_secure_e_httponly_true(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->assertTrue(CookieConfig::requiresHttps());
    }

    public function test_nao_requires_https_quando_secure_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'false';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->assertFalse(CookieConfig::requiresHttps());
    }

    public function test_nao_requires_https_quando_httponly_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'false';
        $this->assertFalse(CookieConfig::requiresHttps());
    }

    public function test_nao_requires_https_quando_ambos_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'false';
        $_ENV['COOKIE_HTTPONLY'] = 'false';
        $this->assertFalse(CookieConfig::requiresHttps());
    }

    // ── isHttps ──────────────────────────────────────────────────────

    public function test_is_https_via_server_https(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(CookieConfig::isHttps());
        unset($_SERVER['HTTPS']);
    }

    public function test_is_https_via_porta_443(): void
    {
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertTrue(CookieConfig::isHttps());
        unset($_SERVER['SERVER_PORT']);
    }

    public function test_is_https_via_forwarded_proto_com_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1'; // IP externo
        $this->assertTrue(CookieConfig::isHttps());
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR'], $_ENV['TRUST_PROXY']);
    }

    public function test_is_https_via_forwarded_proto_loopback_sem_trust_proxy(): void
    {
        // Nginx local (127.0.0.1) deve ser confiado mesmo sem TRUST_PROXY
        $_ENV['TRUST_PROXY'] = 'false';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue(CookieConfig::isHttps());
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR'], $_ENV['TRUST_PROXY']);
    }

    public function test_nao_is_https_forwarded_proto_ip_externo_sem_trust_proxy(): void
    {
        // Atacante externo tentando injetar X-Forwarded-Proto: https
        $_ENV['TRUST_PROXY'] = 'false';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99'; // IP externo malicioso
        $this->assertFalse(CookieConfig::isHttps());
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR'], $_ENV['TRUST_PROXY']);
    }

    public function test_nao_is_https_app_url_nao_influencia(): void
    {
        $_ENV['APP_URL'] = 'https://api.typper.shop';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertFalse(CookieConfig::isHttps());
        unset($_ENV['APP_URL']);
    }

    public function test_nao_is_https_sem_indicadores(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertFalse(CookieConfig::isHttps());
    }

    public function test_server_https_off_nao_e_https(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $this->assertFalse(CookieConfig::isHttps());
        unset($_SERVER['HTTPS']);
    }
}
