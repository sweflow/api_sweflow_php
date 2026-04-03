<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Middlewares\HttpsEnforcerMiddleware;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class HttpsEnforcerMiddlewareTest extends TestCase
{
    private array $originalEnv = [];
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['COOKIE_SECURE', 'COOKIE_HTTPONLY', 'TRUST_PROXY'] as $k) {
            $this->originalEnv[$k] = $_ENV[$k] ?? null;
        }
        foreach (['HTTPS', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO', 'HTTP_ACCEPT', 'REQUEST_URI', 'HTTP_HOST'] as $k) {
            $this->originalServer[$k] = $_SERVER[$k] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        foreach ($this->originalServer as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        parent::tearDown();
    }

    private function buildRequest(string $uri = '/api/test', string $accept = 'application/json'): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_ACCEPT'] = $accept;
        $_SERVER['HTTP_HOST']   = 'localhost:3005';
        return new Request([], [], [], 'GET', $uri);
    }

    private function setHttp(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99'; // IP externo — não é loopback
    }

    private function setHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
    }

    // ── Quando COOKIE_SECURE=false — deve deixar passar ───────────────

    public function test_permite_http_quando_secure_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'false';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->setHttp();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::json(['ok' => true]);
        $res  = $mw->handle($this->buildRequest(), $next);

        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_permite_http_quando_httponly_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'false';
        $this->setHttp();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::json(['ok' => true]);
        $res  = $mw->handle($this->buildRequest(), $next);

        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_permite_http_quando_ambos_false(): void
    {
        $_ENV['COOKIE_SECURE']   = 'false';
        $_ENV['COOKIE_HTTPONLY'] = 'false';
        $this->setHttp();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::json(['ok' => true]);
        $res  = $mw->handle($this->buildRequest(), $next);

        $this->assertSame(200, $res->getStatusCode());
    }

    // ── Quando COOKIE_SECURE=true + COOKIE_HTTPONLY=true via HTTPS — deve passar ──

    public function test_permite_https_quando_secure_e_httponly_true(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->setHttps();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::json(['ok' => true]);
        $res  = $mw->handle($this->buildRequest(), $next);

        $this->assertSame(200, $res->getStatusCode());
    }

    // ── Quando COOKIE_SECURE=true + COOKIE_HTTPONLY=true via HTTP — deve bloquear ──

    public function test_bloqueia_http_api_retorna_json_403(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->setHttp();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::json(['ok' => true]);
        $res  = $mw->handle($this->buildRequest('/api/login', 'application/json'), $next);

        $this->assertSame(403, $res->getStatusCode());
        $body = $res->getBody();
        $this->assertSame('error', $body['status']);
        $this->assertSame('HTTP_NOT_ALLOWED', $body['code']);
    }

    public function test_bloqueia_http_rota_api_sem_accept_json(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->setHttp();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::json(['ok' => true]);
        // URI começa com /api/ — deve retornar JSON mesmo sem Accept: application/json
        $res  = $mw->handle($this->buildRequest('/api/usuarios', 'text/html'), $next);

        $this->assertSame(403, $res->getStatusCode());
        $body = $res->getBody();
        $this->assertSame('HTTP_NOT_ALLOWED', $body['code']);
    }

    public function test_bloqueia_http_browser_retorna_html_403(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->setHttp();

        $mw   = new HttpsEnforcerMiddleware();
        $next = fn($r) => Response::html('<p>ok</p>');
        $res  = $mw->handle($this->buildRequest('/dashboard', 'text/html,application/xhtml+xml'), $next);

        $this->assertSame(403, $res->getStatusCode());
        $body = $res->getBody();
        $this->assertIsString($body);
        $this->assertStringContainsString('HTTPS', $body);
        $this->assertStringContainsString('HTTP não permitido', $body);
    }

    public function test_html_contem_link_https(): void
    {
        $_ENV['COOKIE_SECURE']   = 'true';
        $_ENV['COOKIE_HTTPONLY'] = 'true';
        $this->setHttp();
        $_SERVER['HTTP_HOST']   = 'api.typper.shop';
        $_SERVER['REQUEST_URI'] = '/dashboard';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $req = new Request([], [], [], 'GET', '/dashboard');
        $mw  = new HttpsEnforcerMiddleware();
        $res = $mw->handle($req, fn($r) => Response::html('<p>ok</p>'));

        $this->assertStringContainsString('https://api.typper.shop/dashboard', $res->getBody());
    }
}
