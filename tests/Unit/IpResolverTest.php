<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Support\IpResolver;

/**
 * Testes para IpResolver — resolução segura de IP do cliente.
 *
 * Cobre:
 *   - Spoofing de X-Forwarded-For sem TRUST_PROXY
 *   - Normalização de IPv6
 *   - Prioridade de headers com TRUST_PROXY=true
 */
class IpResolverTest extends TestCase
{
    private array $originalServer = [];
    private array $originalEnv    = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalEnv    = $_ENV;
        unset($_ENV['TRUST_PROXY']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV    = $this->originalEnv;
        parent::tearDown();
    }

    // ── TRUST_PROXY=false (padrão) ────────────────────────────────────

    public function test_usa_remote_addr_sem_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']              = 'false';
        $_SERVER['REMOTE_ADDR']           = '1.2.3.4';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '9.9.9.9';
        $this->assertSame('1.2.3.4', IpResolver::resolve());
    }

    public function test_ignora_x_forwarded_for_sem_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']             = 'false';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 1.1.1.1';
        $this->assertSame('10.0.0.1', IpResolver::resolve());
    }

    public function test_ignora_cf_connecting_ip_sem_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']               = 'false';
        $_SERVER['REMOTE_ADDR']            = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP']  = '5.5.5.5';
        $this->assertSame('10.0.0.1', IpResolver::resolve());
    }

    // ── TRUST_PROXY=true ──────────────────────────────────────────────

    public function test_usa_cf_connecting_ip_com_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']              = 'true';
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '5.5.5.5';
        $this->assertSame('5.5.5.5', IpResolver::resolve());
    }

    public function test_usa_x_real_ip_com_trust_proxy(): void
    {
        $_ENV['TRUST_PROXY']       = 'true';
        $_SERVER['REMOTE_ADDR']    = '10.0.0.1';
        $_SERVER['HTTP_X_REAL_IP'] = '3.3.3.3';
        $this->assertSame('3.3.3.3', IpResolver::resolve());
    }

    public function test_usa_primeiro_ip_do_x_forwarded_for(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2.2.2.2, 3.3.3.3, 4.4.4.4';
        $this->assertSame('2.2.2.2', IpResolver::resolve());
    }

    public function test_prioridade_cf_sobre_x_real_ip(): void
    {
        $_ENV['TRUST_PROXY']              = 'true';
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '5.5.5.5';
        $_SERVER['HTTP_X_REAL_IP']        = '6.6.6.6';
        $this->assertSame('5.5.5.5', IpResolver::resolve());
    }

    // ── Normalização IPv6 ─────────────────────────────────────────────

    public function test_normaliza_ipv6_loopback(): void
    {
        $this->assertSame('127.0.0.1', IpResolver::normalize('::1'));
    }

    public function test_normaliza_ipv4_mapped_ipv6(): void
    {
        $this->assertSame('192.168.1.1', IpResolver::normalize('::ffff:192.168.1.1'));
    }

    public function test_normaliza_ipv4_mapped_ipv6_uppercase(): void
    {
        $this->assertSame('10.0.0.1', IpResolver::normalize('::FFFF:10.0.0.1'));
    }

    public function test_nao_altera_ipv4_normal(): void
    {
        $this->assertSame('1.2.3.4', IpResolver::normalize('1.2.3.4'));
    }

    public function test_nao_altera_ipv6_normal(): void
    {
        $ip = '2001:db8::1';
        $this->assertSame($ip, IpResolver::normalize($ip));
    }

    // ── Proteção contra spoofing ──────────────────────────────────────

    public function test_ip_invalido_no_x_forwarded_for_usa_remote_addr(): void
    {
        $_ENV['TRUST_PROXY']             = 'true';
        $_SERVER['REMOTE_ADDR']          = '1.2.3.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, also-invalid';
        // Deve cair para REMOTE_ADDR pois os IPs são inválidos
        $result = IpResolver::resolve();
        $this->assertSame('1.2.3.4', $result);
    }

    public function test_remote_addr_fallback_quando_headers_vazios(): void
    {
        $_ENV['TRUST_PROXY']    = 'true';
        $_SERVER['REMOTE_ADDR'] = '7.7.7.7';
        $this->assertSame('7.7.7.7', IpResolver::resolve());
    }
}
