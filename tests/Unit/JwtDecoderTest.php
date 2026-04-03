<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Support\JwtDecoder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use DomainException;

/**
 * Testes de segurança para JwtDecoder.
 *
 * CVEs cobertos:
 *   CVE-2015-9235 — JWT alg:none bypass
 *   CVE-2016-10555 — JWT algorithm confusion (RS256 → HS256)
 *   CVE-2022-21449 — Blank signature bypass
 */
class JwtDecoderTest extends TestCase
{
    private string $secret     = 'test-secret-32-chars-minimum-ok!';
    private string $apiSecret  = 'api-secret-32-chars-minimum-ok!!';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['JWT_SECRET']     = $this->secret;
        $_ENV['JWT_API_SECRET'] = $this->apiSecret;
        $_ENV['JWT_ISSUER']     = '';
        $_ENV['JWT_AUDIENCE']   = '';
    }

    protected function tearDown(): void
    {
        unset($_ENV['JWT_SECRET'], $_ENV['JWT_API_SECRET'], $_ENV['JWT_ISSUER'], $_ENV['JWT_AUDIENCE']);
        parent::tearDown();
    }

    // ── Tokens válidos ────────────────────────────────────────────────

    public function test_decode_token_valido_com_jwt_secret(): void
    {
        $token = $this->makeUserToken($this->secret);
        [$payload, $isApi] = JwtDecoder::decodeUser($token);
        $this->assertSame('user-uuid', $payload->sub);
        $this->assertFalse($isApi);
    }

    public function test_decode_token_admin_com_api_secret(): void
    {
        $token = $this->makeUserToken($this->apiSecret);
        [$payload, $isApi] = JwtDecoder::decodeUser($token);
        $this->assertSame('user-uuid', $payload->sub);
        $this->assertTrue($isApi);
    }

    public function test_is_api_token_retorna_true_para_token_de_api(): void
    {
        $token = $this->makeApiToken($this->apiSecret);
        $this->assertTrue(JwtDecoder::isApiToken($token));
    }

    public function test_is_api_token_retorna_false_para_token_de_usuario(): void
    {
        $token = $this->makeUserToken($this->secret);
        $this->assertFalse(JwtDecoder::isApiToken($token));
    }

    // ── CVE-2015-9235: alg:none bypass ───────────────────────────────

    public function test_rejeita_token_alg_none(): void
    {
        // Ataque: assina com alg:none para bypassar verificação de assinatura
        $header  = $this->b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode(['sub' => 'admin', 'tipo' => 'user', 'jti' => 'jti', 'exp' => time() + 3600]));
        $token   = "$header.$payload.";

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    public function test_rejeita_token_alg_none_sem_assinatura(): void
    {
        $header  = $this->b64url(json_encode(['alg' => 'NONE', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode(['sub' => 'admin', 'tipo' => 'user', 'jti' => 'jti', 'exp' => time() + 3600]));
        $token   = "$header.$payload";

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    // ── CVE-2016-10555: algorithm confusion ──────────────────────────

    public function test_rejeita_token_rs256_forjado(): void
    {
        // Ataque: envia RS256 mas assina com HMAC usando chave pública como secret
        $header  = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode(['sub' => 'admin', 'tipo' => 'user', 'jti' => 'jti', 'exp' => time() + 3600]));
        $sig     = $this->b64url('fake_rsa_signature');
        $token   = "$header.$payload.$sig";

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    public function test_rejeita_token_hs1_algoritmo_fraco(): void
    {
        $header  = $this->b64url(json_encode(['alg' => 'HS1', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode(['sub' => 'admin', 'tipo' => 'user', 'jti' => 'jti', 'exp' => time() + 3600]));
        $sig     = $this->b64url(hash_hmac('sha1', "$header.$payload", $this->secret, true));
        $token   = "$header.$payload.$sig";

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    // ── Assinatura inválida ───────────────────────────────────────────

    public function test_rejeita_token_assinatura_incorreta(): void
    {
        $token = $this->makeUserToken('outro-secret-completamente-diferente!!');
        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    public function test_rejeita_token_payload_adulterado(): void
    {
        // Gera token válido, adultera o payload sem re-assinar
        $token  = $this->makeUserToken($this->secret);
        $parts  = explode('.', $token);
        $parts[1] = $this->b64url(json_encode(['sub' => 'admin', 'tipo' => 'user', 'nivel_acesso' => 'admin_system', 'jti' => 'jti', 'exp' => time() + 3600]));
        $forged = implode('.', $parts);

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($forged);
    }

    public function test_rejeita_token_expirado(): void
    {
        $token = JWT::encode([
            'sub'  => 'user-uuid',
            'tipo' => 'user',
            'jti'  => 'jti-expired',
            'exp'  => time() - 3600, // expirado há 1h
        ], $this->secret, 'HS256');

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    public function test_rejeita_token_malformado(): void
    {
        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser('token.invalido.aqui');
    }

    public function test_rejeita_token_vazio(): void
    {
        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser('');
    }

    public function test_rejeita_token_com_apenas_dois_segmentos(): void
    {
        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser('header.payload');
    }

    // ── validateUserClaims ────────────────────────────────────────────

    public function test_valida_claims_corretos(): void
    {
        $payload = (object)['sub' => 'uuid', 'tipo' => 'user', 'jti' => 'jti-ok'];
        JwtDecoder::validateUserClaims($payload); // não deve lançar
        $this->assertTrue(true);
    }

    public function test_rejeita_claims_sem_sub(): void
    {
        $this->expectException(DomainException::class);
        JwtDecoder::validateUserClaims((object)['tipo' => 'user', 'jti' => 'jti']);
    }

    public function test_rejeita_claims_tipo_errado(): void
    {
        $this->expectException(DomainException::class);
        JwtDecoder::validateUserClaims((object)['sub' => 'uuid', 'tipo' => 'api', 'jti' => 'jti']);
    }

    public function test_rejeita_claims_sem_jti(): void
    {
        $this->expectException(DomainException::class);
        JwtDecoder::validateUserClaims((object)['sub' => 'uuid', 'tipo' => 'user']);
    }

    public function test_valida_issuer_quando_configurado(): void
    {
        $_ENV['JWT_ISSUER'] = 'https://api.example.com';
        $payload = (object)['sub' => 'uuid', 'tipo' => 'user', 'jti' => 'jti', 'iss' => 'https://api.example.com'];
        JwtDecoder::validateUserClaims($payload);
        $this->assertTrue(true);
    }

    public function test_rejeita_issuer_incorreto(): void
    {
        $_ENV['JWT_ISSUER'] = 'https://api.example.com';
        $this->expectException(DomainException::class);
        JwtDecoder::validateUserClaims((object)['sub' => 'uuid', 'tipo' => 'user', 'jti' => 'jti', 'iss' => 'https://evil.com']);
    }

    public function test_rejeita_audience_incorreta(): void
    {
        $_ENV['JWT_AUDIENCE'] = 'https://api.example.com';
        $this->expectException(DomainException::class);
        JwtDecoder::validateUserClaims((object)['sub' => 'uuid', 'tipo' => 'user', 'jti' => 'jti', 'aud' => 'https://evil.com']);
    }

    public function test_jwt_secret_nao_configurado_lanca_excecao(): void
    {
        unset($_ENV['JWT_SECRET'], $_ENV['JWT_API_SECRET']);
        $header  = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode(['sub' => 'uuid', 'tipo' => 'user', 'jti' => 'jti', 'exp' => time() + 3600]));
        $token   = "$header.$payload.sig";

        $this->expectException(DomainException::class);
        JwtDecoder::decodeUser($token);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function makeUserToken(string $secret): string
    {
        return JWT::encode([
            'sub'  => 'user-uuid',
            'tipo' => 'user',
            'jti'  => 'jti-' . uniqid(),
            'exp'  => time() + 3600,
        ], $secret, 'HS256');
    }

    private function makeApiToken(string $secret): string
    {
        return JWT::encode([
            'sub'        => 'api-client',
            'tipo'       => 'api',
            'api_access' => true,
            'exp'        => time() + 3600,
        ], $secret, 'HS256');
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
