<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Middlewares\BotBlockerMiddleware;
use Src\Kernel\Support\ThreatScorer;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Testes para BotBlockerMiddleware.
 *
 * Cobre:
 *   - Bloqueio de User-Agents maliciosos conhecidos
 *   - Bloqueio de API sem User-Agent
 *   - Passagem de User-Agents legítimos
 *   - Integração com ThreatScorer
 */
class BotBlockerMiddlewareTest extends TestCase
{
    private array $originalServer = [];
    private array $originalEnv    = [];
    private string $threatDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalEnv    = $_ENV;
        $_ENV['APP_ENV']      = 'testing';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_ENV['TRUST_PROXY']  = 'false';

        // Diretório de threat isolado para testes
        $this->threatDir = sys_get_temp_dir() . '/sweflow_threat_bot_' . uniqid();
        mkdir($this->threatDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV    = $this->originalEnv;
        foreach (glob($this->threatDir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->threatDir);
        parent::tearDown();
    }

    private function makeMw(): BotBlockerMiddleware
    {
        // Scorer isolado com diretório temporário — sem estado entre testes
        $scorer = new ThreatScorer();
        $ref = new \ReflectionProperty(ThreatScorer::class, 'storageDir');
        $ref->setAccessible(true);
        $ref->setValue($scorer, $this->threatDir);
        return new BotBlockerMiddleware($scorer);
    }

    private function buildRequest(string $uri = '/api/test'): Request
    {
        return new Request([], [], [], 'GET', $uri);
    }

    private function next(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    // ── User-Agents maliciosos ────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousUaProvider')]
    public function test_bloqueia_ua_malicioso(string $ua): void
    {
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $mw  = $this->makeMw();
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame(403, $res->getStatusCode(), "UA '$ua' deveria ser bloqueado");
    }

    public static function maliciousUaProvider(): array
    {
        return [
            'sqlmap'       => ['sqlmap/1.7.8#stable (https://sqlmap.org)'],
            'nikto'        => ['Nikto/2.1.6'],
            'nmap'         => ['Nmap Scripting Engine'],
            'masscan'      => ['masscan/1.3'],
            'nuclei'       => ['nuclei/2.9.0'],
            'dirbuster'    => ['DirBuster-1.0-RC1'],
            'gobuster'     => ['gobuster/3.6'],
            'wfuzz'        => ['Wfuzz/2.4'],
            'ffuf'         => ['ffuf/2.1.0'],
            'feroxbuster'  => ['feroxbuster/2.10.0'],
            'hydra'        => ['THC-Hydra'],
            'burpsuite'    => ['BurpSuite Community Edition'],
            'acunetix'     => ['Acunetix-Aspect-Security'],
            'nessus'       => ['Nessus SOAP v0.0.1'],
            'libwww-perl'  => ['libwww-perl/6.67'],
            'scrapy'       => ['Scrapy/2.11.0 (+https://scrapy.org)'],
            'zgrab'        => ['zgrab/0.x'],
            'zgrab2'       => ['zgrab2'],
        ];
    }

    // ── User-Agents legítimos ─────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('legitimateUaProvider')]
    public function test_permite_ua_legitimo(string $ua): void
    {
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $mw      = $this->makeMw();
        $passed  = false;
        $mw->handle($this->buildRequest(), function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed, "UA '$ua' não deveria ser bloqueado");
    }

    public static function legitimateUaProvider(): array
    {
        return [
            'chrome'     => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0'],
            'firefox'    => ['Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0'],
            'safari'     => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/604.1'],
            'curl_novo'  => ['curl/8.4.0'],
            'postman'    => ['PostmanRuntime/7.36.0'],
            'googlebot'  => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'php_client' => ['GuzzleHttp/7.8.1'],
        ];
    }

    // ── API sem User-Agent ────────────────────────────────────────────

    public function test_bloqueia_api_sem_user_agent(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $mw  = $this->makeMw();
        $res = $mw->handle($this->buildRequest('/api/login'), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_permite_pagina_sem_user_agent(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $mw     = $this->makeMw();
        $passed = false;
        $mw->handle($this->buildRequest('/dashboard'), function ($r) use (&$passed) {
            $passed = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($passed);
    }

    // ── Case insensitivity ────────────────────────────────────────────

    public function test_bloqueia_ua_malicioso_uppercase(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'SQLMAP/1.7';
        $mw  = $this->makeMw();
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_bloqueia_ua_malicioso_mixed_case(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'NiKtO/2.1.6';
        $mw  = $this->makeMw();
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame(403, $res->getStatusCode());
    }
}
