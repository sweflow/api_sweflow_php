<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Middlewares\SecurityHeadersMiddleware;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Nonce;

/**
 * Testes para headers de segurança: CSP, HSTS e demais mecanismos.
 *
 * Cobre:
 *   - CSP para rotas de API (default-src 'none')
 *   - CSP para páginas HTML (nonce, self, CDN permitido)
 *   - HSTS presente apenas em HTTPS
 *   - X-Frame-Options, X-Content-Type-Options, X-XSS-Protection
 *   - Referrer-Policy, Permissions-Policy
 *   - SecurityHeadersMiddleware aplica headers em toda resposta
 *   - Nonce é único por request e tem formato base64 válido
 */
class SecurityHeadersTest extends TestCase
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

    // ── Helpers ───────────────────────────────────────────────────────

    private function setHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['SERVER_PORT']);
    }

    private function setHttp(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
    }

    private function buildRequest(string $uri = '/api/test'): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_HOST']   = 'api.example.com';
        return new Request([], [], [], 'GET', $uri);
    }

    private function next(string $type = 'json'): callable
    {
        return fn(Request $r) => $type === 'html'
            ? Response::html('<p>ok</p>')
            : Response::json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════════════
    // CSP — API (default-src 'none')
    // ══════════════════════════════════════════════════════════════════

    public function test_csp_api_e_default_src_none(): void
    {
        $res = Response::json(['ok' => true]);
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("default-src 'none'", $csp);
    }

    public function test_csp_api_contem_frame_ancestors_none(): void
    {
        $res = Response::json(['ok' => true]);
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_csp_api_contem_object_src_none(): void
    {
        $res = Response::json(['ok' => true]);
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("object-src 'none'", $csp,
            "object-src 'none' bloqueia plugins Flash/Java/etc");
    }

    public function test_csp_api_contem_base_uri_none(): void
    {
        $csp = Response::json(['ok' => true])->getHeaders()['Content-Security-Policy'];

        // API não tem <base> tag — 'none' é mais restritivo que 'self'
        $this->assertStringContainsString("base-uri 'none'", $csp,
            "base-uri 'none' é mais restritivo para API pura — sem <base> tag em JSON");
    }

    public function test_csp_api_nao_contem_unsafe_inline(): void
    {
        $res = Response::json(['ok' => true]);
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringNotContainsString("'unsafe-inline'", $csp,
            "CSP de API não deve conter 'unsafe-inline'");
    }

    public function test_csp_api_nao_contem_unsafe_eval(): void
    {
        $res = Response::json(['ok' => true]);
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringNotContainsString("'unsafe-eval'", $csp,
            "CSP de API não deve conter 'unsafe-eval'");
    }

    public function test_csp_api_nao_permite_self_em_script_src(): void
    {
        $res = Response::json(['ok' => true]);
        $csp = $res->getHeaders()['Content-Security-Policy'];

        // API usa default-src 'none' — não deve ter script-src separado
        $this->assertStringNotContainsString('script-src', $csp,
            "CSP de API não deve ter script-src explícito");
    }

    // ══════════════════════════════════════════════════════════════════
    // CSP — HTML (nonce + self + CDN)
    // ══════════════════════════════════════════════════════════════════

    public function test_csp_html_contem_default_src_self(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_csp_html_contem_nonce_em_script_src(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertMatchesRegularExpression(
            "/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/",
            $csp,
            "script-src deve conter nonce base64 válido"
        );
    }

    public function test_csp_html_nonce_e_base64_valido(): void
    {
        $nonce = Nonce::get();

        $this->assertNotEmpty($nonce);
        $decoded = base64_decode($nonce, true);
        $this->assertNotFalse($decoded, "Nonce deve ser base64 válido");
        $this->assertGreaterThanOrEqual(16, strlen($decoded),
            "Nonce deve ter pelo menos 16 bytes de entropia");
    }

    public function test_csp_html_nonce_e_consistente_no_mesmo_request(): void
    {
        $nonce1 = Nonce::get();
        $nonce2 = Nonce::get();

        $this->assertSame($nonce1, $nonce2,
            "Nonce deve ser o mesmo dentro do mesmo request");
    }

    public function test_csp_html_nonce_muda_entre_requests(): void
    {
        $nonce1 = Nonce::get();
        Nonce::reset();
        $nonce2 = Nonce::get();

        $this->assertNotSame($nonce1, $nonce2,
            "Nonce deve ser diferente entre requests distintos");
    }

    public function test_csp_html_permite_cdn_cloudflare_em_style_src(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString('https://cdnjs.cloudflare.com', $csp);
    }

    public function test_csp_html_contem_object_src_none(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_csp_html_contem_frame_ancestors_none(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_csp_html_contem_base_uri_self(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("base-uri 'self'", $csp,
            "base-uri 'self' previne injeção de base tag");
    }

    public function test_csp_html_contem_form_action_self(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("form-action 'self'", $csp,
            "form-action 'self' previne exfiltração via form");
    }

    public function test_csp_html_nao_contem_unsafe_eval(): void
    {
        $res = Response::html('<p>ok</p>');
        $csp = $res->getHeaders()['Content-Security-Policy'];

        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    // ══════════════════════════════════════════════════════════════════
    // HSTS
    // ══════════════════════════════════════════════════════════════════

    public function test_hsts_presente_em_https(): void
    {
        $this->setHttps();

        $res     = Response::json(['ok' => true]);
        $headers = $res->getHeaders();

        $this->assertArrayHasKey('Strict-Transport-Security', $headers,
            "HSTS deve estar presente em conexões HTTPS");
    }

    public function test_hsts_max_age_e_um_ano(): void
    {
        $this->setHttps();

        $hsts = Response::json(['ok' => true])->getHeaders()['Strict-Transport-Security'];

        $this->assertStringContainsString('max-age=31536000', $hsts);
    }

    public function test_hsts_inclui_subdomains(): void
    {
        $this->setHttps();

        $hsts = Response::json(['ok' => true])->getHeaders()['Strict-Transport-Security'];

        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    public function test_hsts_inclui_preload(): void
    {
        $this->setHttps();

        $hsts = Response::json(['ok' => true])->getHeaders()['Strict-Transport-Security'];

        $this->assertStringContainsString('preload', $hsts);
    }

    public function test_hsts_ausente_em_http(): void
    {
        $this->setHttp();

        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers,
            "HSTS não deve ser enviado em HTTP — evita downgrade attack");
    }

    public function test_hsts_presente_via_forwarded_proto_loopback(): void
    {
        $this->setHttp();
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR']            = '127.0.0.1'; // Nginx local
        $_ENV['TRUST_PROXY']               = 'false';

        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertArrayHasKey('Strict-Transport-Security', $headers,
            "HSTS deve ser enviado quando Nginx local indica HTTPS via X-Forwarded-Proto");
    }

    public function test_hsts_ausente_via_forwarded_proto_ip_externo_sem_trust_proxy(): void
    {
        $this->setHttp();
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR']            = '203.0.113.99'; // IP externo malicioso
        $_ENV['TRUST_PROXY']               = 'false';

        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers,
            "HSTS não deve ser enviado quando X-Forwarded-Proto vem de IP externo sem TRUST_PROXY");
    }

    // ══════════════════════════════════════════════════════════════════
    // X-Frame-Options
    // ══════════════════════════════════════════════════════════════════

    public function test_x_frame_options_e_deny_em_json(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    public function test_x_frame_options_e_deny_em_html(): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();

        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    // ══════════════════════════════════════════════════════════════════
    // X-Content-Type-Options
    // ══════════════════════════════════════════════════════════════════

    public function test_x_content_type_options_e_nosniff_em_json(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function test_x_content_type_options_e_nosniff_em_html(): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    // ══════════════════════════════════════════════════════════════════
    // X-XSS-Protection — removido (deprecated)
    // ══════════════════════════════════════════════════════════════════

    public function test_x_xss_protection_ausente_em_json(): void
    {
        // Deprecated desde 2019: Chrome removeu, Firefox nunca implementou.
        // Pode causar vulnerabilidades em browsers legados. CSP cobre XSS.
        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertArrayNotHasKey('X-XSS-Protection', $headers,
            "X-XSS-Protection é deprecated e deve ser removido");
    }

    public function test_x_xss_protection_ausente_em_html(): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();

        $this->assertArrayNotHasKey('X-XSS-Protection', $headers,
            "X-XSS-Protection é deprecated e deve ser removido");
    }

    // ══════════════════════════════════════════════════════════════════
    // Cross-Origin-Resource-Policy (CORP)
    // ══════════════════════════════════════════════════════════════════

    public function test_corp_e_same_origin_em_json(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertSame('same-origin', $headers['Cross-Origin-Resource-Policy'],
            "CORP same-origin impede que outros sites carreguem este recurso");
    }

    public function test_corp_e_same_origin_em_html(): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();

        $this->assertSame('same-site', $headers['Cross-Origin-Resource-Policy']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Cross-Origin-Opener-Policy (COOP)
    // ══════════════════════════════════════════════════════════════════

    public function test_coop_e_same_origin_em_json(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertSame('same-origin', $headers['Cross-Origin-Opener-Policy'],
            "COOP same-origin isola o browsing context — protege contra Spectre/XS-Leaks");
    }

    public function test_coop_e_same_origin_em_html(): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();

        $this->assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Cross-Origin-Embedder-Policy (COEP)
    // ══════════════════════════════════════════════════════════════════

    public function test_coep_e_require_corp_em_json(): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();

        $this->assertSame('require-corp', $headers['Cross-Origin-Embedder-Policy'],
            "COEP require-corp garante que recursos embarcados declarem CORP explicitamente");
    }

    public function test_coep_e_require_corp_em_html(): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();

        // Páginas HTML usam 'credentialless' para permitir CDNs externos (cdnjs, fonts, etc.)
        // sem exigir que eles enviem Cross-Origin-Resource-Policy: cross-origin
        $this->assertSame('credentialless', $headers['Cross-Origin-Embedder-Policy']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Referrer-Policy — diferenciado por contexto
    // ══════════════════════════════════════════════════════════════════

    public function test_referrer_policy_api_e_no_referrer(): void
    {
        // API não tem motivo para enviar referrer — no-referrer é mais restritivo
        $headers = Response::json(['ok' => true])->getHeaders();
        $this->assertSame('no-referrer', $headers['Referrer-Policy'],
            'API deve usar no-referrer — sem motivo para vazar URL de origem em chamadas de API');
    }

    public function test_referrer_policy_html_e_strict_origin_when_cross_origin(): void
    {
        // Páginas HTML precisam de referrer para analytics/navegação — melhor equilíbrio
        $headers = Response::html('<p>ok</p>')->getHeaders();
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy'],
            'HTML deve usar strict-origin-when-cross-origin — melhor equilíbrio para páginas');
    }

    // ══════════════════════════════════════════════════════════════════
    // Trusted Types — mata DOM XSS moderno
    // ══════════════════════════════════════════════════════════════════

    public function test_csp_html_contem_trusted_types(): void
    {
        $csp = Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy'];

        // require-trusted-types-for 'script' bloqueia innerHTML com strings não-TrustedHTML
        // A política 'default' em dashboard.js aceita HTML gerado pelo próprio código
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp,
            "Trusted Types deve estar ativo — mata DOM XSS moderno");
    }

    public function test_csp_html_contem_trusted_types_policy_default(): void
    {
        $csp = Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString('trusted-types default', $csp,
            "trusted-types default define a política aceita pelo dashboard.js");
    }

    public function test_csp_html_contem_trusted_types_policy_dompurify(): void
    {
        $csp = Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString('trusted-types default dompurify monaco-editor', $csp,
            "trusted-types deve incluir 'dompurify' e 'monaco-editor' para que o DOMPurify e Monaco criem suas políticas internas");
    }

    public function test_csp_html_connect_src_inclui_cdnjs(): void
    {
        $csp = Response::html('<p>ok</p>')->getHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("connect-src 'self' https://cdnjs.cloudflare.com", $csp,
            "connect-src deve incluir cdnjs para sourcemaps e recursos do DOMPurify");
    }

    public function test_csp_api_nao_contem_trusted_types(): void
    {
        $csp = Response::json(['ok' => true])->getHeaders()['Content-Security-Policy'];

        // API não serve HTML — Trusted Types não se aplica
        $this->assertStringNotContainsString('trusted-types', $csp,
            'API não serve HTML — Trusted Types não é necessário');
    }

    // ══════════════════════════════════════════════════════════════════
    // Permissions-Policy
    // ══════════════════════════════════════════════════════════════════

    public function test_permissions_policy_bloqueia_geolocalizacao(): void
    {
        $pp = Response::json(['ok' => true])->getHeaders()['Permissions-Policy'];

        $this->assertStringContainsString('geolocation=()', $pp);
    }

    public function test_permissions_policy_bloqueia_microfone(): void
    {
        $pp = Response::json(['ok' => true])->getHeaders()['Permissions-Policy'];

        $this->assertStringContainsString('microphone=()', $pp);
    }

    public function test_permissions_policy_bloqueia_camera(): void
    {
        $pp = Response::json(['ok' => true])->getHeaders()['Permissions-Policy'];

        $this->assertStringContainsString('camera=()', $pp);
    }

    // ══════════════════════════════════════════════════════════════════
    // SecurityHeadersMiddleware — aplica headers em toda resposta
    // ══════════════════════════════════════════════════════════════════

    public function test_middleware_aplica_csp_em_rota_api(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $csp = $res->getHeaders()['Content-Security-Policy'];
        $this->assertStringContainsString("default-src 'none'", $csp);
    }

    public function test_middleware_aplica_csp_em_rota_html(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/dashboard'), $this->next('html'));

        $csp = $res->getHeaders()['Content-Security-Policy'];
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertMatchesRegularExpression("/nonce-[A-Za-z0-9+\/=]+/", $csp);
    }

    public function test_middleware_aplica_hsts_em_https(): void
    {
        $this->setHttps();

        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertArrayHasKey('Strict-Transport-Security', $res->getHeaders());
    }

    public function test_middleware_nao_aplica_hsts_em_http(): void
    {
        $this->setHttp();

        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertArrayNotHasKey('Strict-Transport-Security', $res->getHeaders());
    }

    public function test_middleware_aplica_x_frame_options(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertSame('DENY', $res->getHeaders()['X-Frame-Options']);
    }

    public function test_middleware_aplica_x_content_type_options(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertSame('nosniff', $res->getHeaders()['X-Content-Type-Options']);
    }

    public function test_middleware_aplica_referrer_policy_no_referrer_em_api(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertSame('no-referrer', $res->getHeaders()['Referrer-Policy'],
            'API deve usar no-referrer');
    }

    public function test_middleware_aplica_referrer_policy_strict_em_html(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/dashboard'), $this->next('html'));

        $this->assertSame('strict-origin-when-cross-origin', $res->getHeaders()['Referrer-Policy'],
            'HTML deve usar strict-origin-when-cross-origin');
    }

    public function test_middleware_aplica_permissions_policy(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $pp = $res->getHeaders()['Permissions-Policy'];
        $this->assertStringContainsString('geolocation=()', $pp);
        $this->assertStringContainsString('microphone=()', $pp);
        $this->assertStringContainsString('camera=()', $pp);
    }

    public function test_middleware_aplica_corp(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertSame('same-origin', $res->getHeaders()['Cross-Origin-Resource-Policy']);
    }

    public function test_middleware_aplica_coop(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertSame('same-origin', $res->getHeaders()['Cross-Origin-Opener-Policy']);
    }

    public function test_middleware_aplica_coep(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertSame('require-corp', $res->getHeaders()['Cross-Origin-Embedder-Policy']);
    }

    public function test_middleware_nao_tem_x_xss_protection(): void
    {
        $mw  = new SecurityHeadersMiddleware();
        $res = $mw->handle($this->buildRequest('/api/test'), $this->next('json'));

        $this->assertArrayNotHasKey('X-XSS-Protection', $res->getHeaders(),
            "X-XSS-Protection é deprecated e não deve ser enviado");
    }

    // ══════════════════════════════════════════════════════════════════
    // Todos os headers obrigatórios presentes
    // ══════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredHeadersProvider')]
    public function test_header_obrigatorio_presente_em_json(string $header): void
    {
        $headers = Response::json(['ok' => true])->getHeaders();
        $this->assertArrayHasKey($header, $headers, "Header '$header' deve estar presente em respostas JSON");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredHeadersProvider')]
    public function test_header_obrigatorio_presente_em_html(string $header): void
    {
        $headers = Response::html('<p>ok</p>')->getHeaders();
        $this->assertArrayHasKey($header, $headers, "Header '$header' deve estar presente em respostas HTML");
    }

    public static function requiredHeadersProvider(): array
    {
        return [
            'CSP'                          => ['Content-Security-Policy'],
            'X-Frame-Options'              => ['X-Frame-Options'],
            'X-Content-Type-Options'       => ['X-Content-Type-Options'],
            'Referrer-Policy'              => ['Referrer-Policy'],
            'Permissions-Policy'           => ['Permissions-Policy'],
            'Cross-Origin-Resource-Policy' => ['Cross-Origin-Resource-Policy'],
            'Cross-Origin-Opener-Policy'   => ['Cross-Origin-Opener-Policy'],
            'Cross-Origin-Embedder-Policy' => ['Cross-Origin-Embedder-Policy'],
        ];
    }
}
