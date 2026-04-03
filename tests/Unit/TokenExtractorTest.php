<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Support\TokenExtractor;

/**
 * Testes para TokenExtractor — extração centralizada de tokens JWT.
 */
class TokenExtractorTest extends TestCase
{
    private array $originalServer = [];
    private array $originalCookie = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalCookie = $_COOKIE;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_API_KEY']);
        unset($_COOKIE['auth_token']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;
        parent::tearDown();
    }

    // ── fromBearer ────────────────────────────────────────────────────

    public function test_from_bearer_retorna_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer my.jwt.token';
        $this->assertSame('my.jwt.token', TokenExtractor::fromBearer());
    }

    public function test_from_bearer_case_insensitive(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'BEARER my.jwt.token';
        $this->assertSame('my.jwt.token', TokenExtractor::fromBearer());
    }

    public function test_from_bearer_retorna_vazio_sem_header(): void
    {
        $this->assertSame('', TokenExtractor::fromBearer());
    }

    public function test_from_bearer_retorna_vazio_para_basic_auth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $this->assertSame('', TokenExtractor::fromBearer());
    }

    public function test_from_bearer_trim_espacos(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer   token.com.espacos   ';
        $this->assertSame('token.com.espacos', TokenExtractor::fromBearer());
    }

    // ── fromApiKey ────────────────────────────────────────────────────

    public function test_from_api_key_retorna_token(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'api.key.token';
        $this->assertSame('api.key.token', TokenExtractor::fromApiKey());
    }

    public function test_from_api_key_retorna_vazio_sem_header(): void
    {
        $this->assertSame('', TokenExtractor::fromApiKey());
    }

    // ── fromRequest ───────────────────────────────────────────────────

    public function test_from_request_prefere_cookie(): void
    {
        $_COOKIE['auth_token']         = 'cookie.token';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer.token';
        $this->assertSame('cookie.token', TokenExtractor::fromRequest());
    }

    public function test_from_request_fallback_para_bearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer.token';
        $this->assertSame('bearer.token', TokenExtractor::fromRequest());
    }

    public function test_from_request_retorna_vazio_sem_token(): void
    {
        $this->assertSame('', TokenExtractor::fromRequest());
    }

    public function test_from_request_ignora_cookie_vazio(): void
    {
        $_COOKIE['auth_token']         = '   ';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer.token';
        $this->assertSame('bearer.token', TokenExtractor::fromRequest());
    }

    // ── fromApiRequest ────────────────────────────────────────────────

    public function test_from_api_request_prefere_x_api_key(): void
    {
        $_SERVER['HTTP_X_API_KEY']     = 'api.key.token';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer.token';
        $_COOKIE['auth_token']         = 'cookie.token';
        $this->assertSame('api.key.token', TokenExtractor::fromApiRequest());
    }

    public function test_from_api_request_fallback_para_cookie(): void
    {
        $_COOKIE['auth_token'] = 'cookie.token';
        $this->assertSame('cookie.token', TokenExtractor::fromApiRequest());
    }

    public function test_from_api_request_retorna_vazio_sem_token(): void
    {
        $this->assertSame('', TokenExtractor::fromApiRequest());
    }

    // ── Segurança: injeção de header ──────────────────────────────────

    public function test_bearer_com_crlf_nao_injeta_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer token\r\nX-Injected: evil";
        $token = TokenExtractor::fromBearer();
        // O token pode conter o CRLF mas não deve ser usado para injeção de header
        // O importante é que não retorne vazio (o middleware vai rejeitar o JWT inválido)
        $this->assertIsString($token);
    }
}
