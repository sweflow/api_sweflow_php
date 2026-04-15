<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Support\CookieConfig;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\RequestContext;

/**
 * Garante que RequestContext e os helpers estáticos (CookieConfig, IpResolver)
 * nunca divergem em seus resultados.
 *
 * Regra arquitetural: a lógica de detecção de HTTPS e IP existe em dois lugares
 * (RequestContext para DI, helpers estáticos para código legado). Este teste
 * é a única barreira que impede os dois de divergirem silenciosamente.
 *
 * Se este teste falhar: atualize AMBAS as implementações juntas.
 */
class RequestContextConsistencyTest extends TestCase
{
    private array $originalServer = [];
    private array $originalEnv    = [];

    protected function setUp(): void
    {
        parent::setUp();
        $serverKeys = ['HTTPS', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO', 'REMOTE_ADDR',
                       'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
        $envKeys    = ['TRUST_PROXY', 'APP_URL'];

        foreach ($serverKeys as $k) { $this->originalServer[$k] = $_SERVER[$k] ?? null; unset($_SERVER[$k]); }
        foreach ($envKeys    as $k) { $this->originalEnv[$k]    = $_ENV[$k]    ?? null; unset($_ENV[$k]); }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalServer as $k => $v) {
            if ($v === null) unset($_SERVER[$k]); else $_SERVER[$k] = $v;
        }
        foreach ($this->originalEnv as $k => $v) {
            if ($v === null) unset($_ENV[$k]); else $_ENV[$k] = $v;
        }
        parent::tearDown();
    }

    // ── Cenários de HTTPS ─────────────────────────────────────────────────

    /** @dataProvider httpsScenarios */
    #[\PHPUnit\Framework\Attributes\DataProvider('httpsScenarios')]
    public function test_isSecure_consistente_entre_RequestContext_e_CookieConfig(
        array $server, array $env, bool $expected, string $desc
    ): void {
        foreach ($server as $k => $v) $_SERVER[$k] = $v;
        foreach ($env    as $k => $v) $_ENV[$k]    = $v;

        $ctx = new RequestContext();

        $this->assertSame($expected, $ctx->isSecure(),        "RequestContext::isSecure() — {$desc}");
        $this->assertSame($expected, CookieConfig::isHttps(), "CookieConfig::isHttps() — {$desc}");

        // A regra central: os dois NUNCA podem divergir
        $this->assertSame(
            $ctx->isSecure(),
            CookieConfig::isHttps(),
            "DIVERGÊNCIA DETECTADA em: {$desc}"
        );
    }

    public static function httpsScenarios(): array
    {
        return [
            'HTTP puro sem proxy'                => [[], [], false, 'sem HTTPS, sem proxy'],
            'HTTPS direto'                       => [['HTTPS' => 'on'], [], true, 'HTTPS=on'],
            'HTTPS off explícito'                => [['HTTPS' => 'off'], [], false, 'HTTPS=off'],
            'Porta 443'                          => [['SERVER_PORT' => '443'], [], true, 'porta 443'],
            'X-Forwarded-Proto sem trust'        => [['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '1.2.3.4'], [], false, 'XFP sem TRUST_PROXY'],
            'X-Forwarded-Proto com trust'        => [['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '1.2.3.4'], ['TRUST_PROXY' => 'true'], true, 'XFP com TRUST_PROXY'],
            'X-Forwarded-Proto de loopback'      => [['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '127.0.0.1'], [], true, 'XFP de loopback'],
            'FastCGI socket + TRUST_PROXY + URL' => [[], ['TRUST_PROXY' => 'true', 'APP_URL' => 'https://api.vupi.us'], true, 'FastCGI socket Unix'],
            'TRUST_PROXY + APP_URL http'         => [[], ['TRUST_PROXY' => 'true', 'APP_URL' => 'http://api.vupi.us'], false, 'TRUST_PROXY mas APP_URL http'],
        ];
    }

    // ── Cenários de IP ────────────────────────────────────────────────────

    /** @dataProvider ipScenarios */
    #[\PHPUnit\Framework\Attributes\DataProvider('ipScenarios')]
    public function test_getClientIp_consistente_entre_RequestContext_e_IpResolver(
        array $server, array $env, string $expected, string $desc
    ): void {
        foreach ($server as $k => $v) $_SERVER[$k] = $v;
        foreach ($env    as $k => $v) $_ENV[$k]    = $v;

        $ctx = new RequestContext();

        $this->assertSame($expected, $ctx->getClientIp(),  "RequestContext::getClientIp() — {$desc}");
        $this->assertSame($expected, IpResolver::resolve(), "IpResolver::resolve() — {$desc}");

        $this->assertSame(
            $ctx->getClientIp(),
            IpResolver::resolve(),
            "DIVERGÊNCIA DETECTADA em: {$desc}"
        );
    }

    public static function ipScenarios(): array
    {
        return [
            'REMOTE_ADDR simples'              => [['REMOTE_ADDR' => '1.2.3.4'], [], '1.2.3.4', 'REMOTE_ADDR direto'],
            'IPv6 loopback normalizado'        => [['REMOTE_ADDR' => '::1'], [], '127.0.0.1', '::1 → 127.0.0.1'],
            'IPv4-mapped IPv6'                 => [['REMOTE_ADDR' => '::ffff:1.2.3.4'], [], '1.2.3.4', '::ffff:x.x.x.x'],
            'CF-Connecting-IP com trust'       => [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'], ['TRUST_PROXY' => 'true'], '5.6.7.8', 'Cloudflare'],
            'X-Real-IP com trust'              => [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '9.10.11.12'], ['TRUST_PROXY' => 'true'], '9.10.11.12', 'X-Real-IP'],
            'X-Forwarded-For lista com trust'  => [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '3.3.3.3, 4.4.4.4'], ['TRUST_PROXY' => 'true'], '3.3.3.3', 'XFF lista'],
            'CF ignorado sem trust'            => [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'], [], '10.0.0.1', 'CF sem TRUST_PROXY'],
        ];
    }
}
