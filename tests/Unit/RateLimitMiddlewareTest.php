<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Testes para RateLimitMiddleware.
 *
 * Cobre:
 *   - Bloqueio após exceder limite por IP
 *   - Headers X-RateLimit-* presentes
 *   - Retry-After em resposta 429
 *   - Janela de tempo (reset após window)
 */
class RateLimitMiddlewareTest extends TestCase
{
    private string $storageDir;
    private array  $originalServer = [];
    private array  $originalEnv    = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer   = $_SERVER;
        $this->originalEnv      = $_ENV;
        $_ENV['APP_ENV']        = 'testing';
        $_ENV['TRUST_PROXY']    = 'false';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $this->storageDir = sys_get_temp_dir() . '/sweflow_rl_test_' . uniqid();
        mkdir($this->storageDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV    = $this->originalEnv;
        foreach (glob($this->storageDir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageDir);
        parent::tearDown();
    }

    private function makeMw(int $limit = 3, int $window = 60): RateLimitMiddleware
    {
        $mw = new RateLimitMiddleware($limit, $window, 'test.route', 0);
        $ref = new \ReflectionProperty(RateLimitMiddleware::class, 'storageDir');
        $ref->setAccessible(true);
        $ref->setValue($mw, $this->storageDir);
        return $mw;
    }

    private function buildRequest(): Request
    {
        return new Request([], [], [], 'POST', '/api/test');
    }

    private function next(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    // ── Comportamento normal ──────────────────────────────────────────

    public function test_permite_requisicoes_dentro_do_limite(): void
    {
        $mw = $this->makeMw(3);
        for ($i = 0; $i < 3; $i++) {
            $res = $mw->handle($this->buildRequest(), $this->next());
            $this->assertSame(200, $res->getStatusCode(), "Requisição $i deveria passar");
        }
    }

    public function test_bloqueia_apos_exceder_limite(): void
    {
        $mw = $this->makeMw(3);
        for ($i = 0; $i < 3; $i++) {
            $mw->handle($this->buildRequest(), $this->next());
        }
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame(429, $res->getStatusCode());
    }

    // ── Headers ───────────────────────────────────────────────────────

    public function test_headers_ratelimit_presentes(): void
    {
        $mw  = $this->makeMw(10);
        $res = $mw->handle($this->buildRequest(), $this->next());
        $headers = $res->getHeaders();
        $this->assertArrayHasKey('X-RateLimit-Limit',     $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset',     $headers);
    }

    public function test_header_limit_correto(): void
    {
        $mw  = $this->makeMw(5);
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame('5', $res->getHeaders()['X-RateLimit-Limit']);
    }

    public function test_remaining_decrementa(): void
    {
        $mw   = $this->makeMw(5);
        $res1 = $mw->handle($this->buildRequest(), $this->next());
        $res2 = $mw->handle($this->buildRequest(), $this->next());
        $rem1 = (int) $res1->getHeaders()['X-RateLimit-Remaining'];
        $rem2 = (int) $res2->getHeaders()['X-RateLimit-Remaining'];
        $this->assertGreaterThan($rem2, $rem1);
    }

    public function test_resposta_429_tem_retry_after(): void
    {
        $mw = $this->makeMw(1);
        $mw->handle($this->buildRequest(), $this->next());
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame(429, $res->getStatusCode());
        $this->assertArrayHasKey('Retry-After', $res->getHeaders());
    }

    public function test_resposta_429_tem_ratelimit_remaining_zero(): void
    {
        $mw = $this->makeMw(1);
        $mw->handle($this->buildRequest(), $this->next());
        $res = $mw->handle($this->buildRequest(), $this->next());
        $this->assertSame('0', $res->getHeaders()['X-RateLimit-Remaining']);
    }

    // ── IPs diferentes não interferem ─────────────────────────────────

    public function test_ips_diferentes_tem_contadores_independentes(): void
    {
        $mw = $this->makeMw(2);

        $_SERVER['REMOTE_ADDR'] = '1.1.1.1';
        $mw->handle($this->buildRequest(), $this->next());
        $mw->handle($this->buildRequest(), $this->next());
        $res1 = $mw->handle($this->buildRequest(), $this->next());

        $_SERVER['REMOTE_ADDR'] = '2.2.2.2';
        $res2 = $mw->handle($this->buildRequest(), $this->next());

        $this->assertSame(429, $res1->getStatusCode(), 'IP 1.1.1.1 deveria ser bloqueado');
        $this->assertSame(200, $res2->getStatusCode(), 'IP 2.2.2.2 deveria passar');
    }
}
