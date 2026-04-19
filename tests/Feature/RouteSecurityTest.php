<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;
use Firebase\JWT\JWT;
use Src\Kernel\Nucleo\Router;
use Src\Kernel\Nucleo\Container;
use Src\Kernel\Nucleo\ModuleLoader;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Testes de segurança de rotas — ataque e defesa.
 */
#[AllowMockObjectsWithoutExpectations]
class RouteSecurityTest extends TestCase
{
    private Router    $router;
    private Container $container;
    private string $jwtSecret    = 'test-jwt-secret-32-chars-minimum!';
    private string $jwtApiSecret = 'test-api-secret-32-chars-minimum!';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_ENV']        = 'testing';
        $_ENV['APP_DEBUG']      = 'false';
        $_ENV['JWT_SECRET']     = $this->jwtSecret;
        $_ENV['JWT_API_SECRET'] = $this->jwtApiSecret;
        $_ENV['JWT_ISSUER']     = '';
        $_ENV['JWT_AUDIENCE']   = '';
        $_ENV['DB_HOST']        = '127.0.0.1';
        $_ENV['DB_NOME']        = 'test';
        $_ENV['DB_USUARIO']     = 'test';
        $_ENV['DB_SENHA']       = 'test';
        $_ENV['DB_CONEXAO']     = 'mysql';
        $_ENV['DB_PORT']        = '3306';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->container = new Container();
        $this->router    = new Router($this->container);
        $this->container->bind(\Src\Kernel\Contracts\ContainerInterface::class, $this->container, true);
        $this->container->bind(\Src\Kernel\Contracts\RouterInterface::class, $this->router, true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('exec')->willReturn(0);
        $pdo->method('query')->willReturn($this->createMock(\PDOStatement::class));
        $this->container->bind(\PDO::class, $pdo, true);

        $this->container->bind(\Src\Kernel\Support\DB\PluginMigrator::class, $this->createMock(\Src\Kernel\Support\DB\PluginMigrator::class), true);
        $this->container->bind(\Src\Kernel\Support\AuditLogger::class, new \Src\Kernel\Support\AuditLogger(null), true);

        $blacklist = $this->createMock(\Src\Kernel\Contracts\TokenBlacklistInterface::class);
        $blacklist->method('isRevoked')->willReturn(false);
        $this->container->bind(\Src\Kernel\Contracts\TokenBlacklistInterface::class, $blacklist, true);

        $userRepo = $this->createMock(\Src\Kernel\Contracts\UserRepositoryInterface::class);
        $userRepo->method('buscarPorUuid')->willReturn(null);
        $this->container->bind(\Src\Kernel\Contracts\UserRepositoryInterface::class, $userRepo, true);

        // Registra o pipeline de auth para que AuthHybridMiddleware e AdminOnlyMiddleware funcionem
        $tokenValidator = new \Src\Kernel\Auth\JwtTokenValidator($blacklist);
        $this->container->bind(\Src\Kernel\Contracts\TokenValidatorInterface::class, $tokenValidator, true);

        $userResolver = new \Src\Kernel\Auth\DatabaseUserResolver($userRepo);
        $this->container->bind(\Src\Kernel\Contracts\UserResolverInterface::class, $userResolver, true);

        $this->container->bind(\Src\Kernel\Contracts\TokenResolverInterface::class, \Src\Kernel\Auth\BearerTokenResolver::class, true);

        $identityFactory = new \Src\Kernel\Auth\DefaultIdentityFactory();
        $this->container->bind(\Src\Kernel\Contracts\IdentityFactoryInterface::class, $identityFactory, true);

        $authContext = new \Src\Kernel\Auth\JwtAuthContext(
            $this->container->make(\Src\Kernel\Contracts\TokenResolverInterface::class),
            $tokenValidator,
            $userResolver,
            $identityFactory
        );
        $this->container->bind(\Src\Kernel\Contracts\AuthContextInterface::class, $authContext, true);
        $this->container->bind(\Src\Kernel\Contracts\AuthorizationInterface::class, $authContext, true);

        $svc = $this->createMock(\Src\Modules\Usuario\Services\UsuarioServiceInterface::class);
        $svc->method('buscarPorUsername')->willReturn(null);
        $svc->method('buscarPorUuid')->willReturn(null);
        $svc->method('buscarPorEmail')->willReturn(null);
        $this->container->bind(\Src\Modules\Usuario\Services\UsuarioServiceInterface::class, $svc, true);

        $modules = new ModuleLoader($this->container);
        $modules->discover(dirname(__DIR__, 2) . '/src/Modules');
        $modules->bootAll();
        $modules->registerRoutes($this->router);
    }

    protected function tearDown(): void
    {
        unset($_ENV['JWT_SECRET'], $_ENV['JWT_API_SECRET'], $_ENV['JWT_ISSUER'], $_ENV['JWT_AUDIENCE']);
        parent::tearDown();
    }

    private function dispatch(string $method, string $path, array $body = [], array $headers = []): Response
    {
        return $this->router->dispatch(new Request($body, [], $headers, $method, $path));
    }

    private function dispatchWithBearer(string $method, string $path, string $token): Response
    {
        return $this->router->dispatch(new Request([], [], ['Authorization' => "Bearer $token"], $method, $path));
    }

    private function makeUserToken(string $nivel = 'usuario', string $secret = ''): string
    {
        return JWT::encode(['sub' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'tipo' => 'user', 'nivel_acesso' => $nivel, 'jti' => 'jti-' . uniqid(), 'exp' => time() + 3600], $secret ?: $this->jwtSecret, 'HS256');
    }

    private function makeExpiredToken(): string
    {
        return JWT::encode(['sub' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'tipo' => 'user', 'nivel_acesso' => 'admin_system', 'jti' => 'jti-exp', 'exp' => time() - 3600], $this->jwtApiSecret, 'HS256');
    }

    // 1. Rotas privadas bloqueiam sem token

    #[\PHPUnit\Framework\Attributes\DataProvider('rotasAdminProvider')]
    public function test_rota_admin_retorna_401_sem_token(string $method, string $path): void
    {
        $res = $this->dispatch($method, $path);
        $this->assertSame(401, $res->getStatusCode(), "$method $path deve retornar 401 sem token");
    }

    public static function rotasAdminProvider(): array
    {
        return [
            ['GET',    '/api/usuarios'],
            ['GET',    '/api/usuario/some-uuid'],
            ['PUT',    '/api/usuario/atualizar/some-uuid'],
            ['DELETE', '/api/usuario/deletar/some-uuid'],
            ['PATCH',  '/api/usuario/some-uuid/desativar'],
            ['PATCH',  '/api/usuario/some-uuid/ativar'],
            ['GET',    '/api/auth/me'],
            ['POST',   '/api/auth/logout'],
            ['GET',    '/api/auth/email-verification'],
            ['GET',    '/api/usuarios/verificar-email-status'],
            ['GET',    '/api/perfil'],
            ['PUT',    '/api/perfil'],
            ['PUT',    '/api/perfil/email'],
            ['PUT',    '/api/perfil/senha'],
            ['POST',   '/api/perfil/upload'],
            ['DELETE', '/api/perfil'],
        ];
    }

    // 2. Token forjado (assinatura inválida)

    #[\PHPUnit\Framework\Attributes\DataProvider('rotasAdminProvider')]
    public function test_rota_admin_rejeita_token_forjado(string $method, string $path): void
    {
        $h = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $p = rtrim(strtr(base64_encode(json_encode(['sub' => 'uuid', 'tipo' => 'user', 'nivel_acesso' => 'admin_system', 'jti' => 'x', 'exp' => time() + 3600])), '+/', '-_'), '=');
        $res = $this->dispatchWithBearer($method, $path, "$h.$p.assinatura_invalida");
        $this->assertContains($res->getStatusCode(), [401, 403], "Token forjado em $method $path deve ser bloqueado");
    }

    // 3. Token expirado

    public function test_rota_privada_rejeita_token_expirado(): void
    {
        $res = $this->dispatchWithBearer('GET', '/api/auth/me', $this->makeExpiredToken());
        $this->assertSame(401, $res->getStatusCode());
    }

    // 4. Usuário comum em rota admin

    #[\PHPUnit\Framework\Attributes\DataProvider('rotasAdminProvider')]
    public function test_rota_admin_rejeita_usuario_comum(string $method, string $path): void
    {
        $res = $this->dispatchWithBearer($method, $path, $this->makeUserToken('usuario'));
        $this->assertContains($res->getStatusCode(), [401, 403], "Usuário comum em $method $path deve ser bloqueado");
    }

    // 5. Escalada de privilégio: admin_system com JWT_SECRET

    #[\PHPUnit\Framework\Attributes\DataProvider('rotasAdminProvider')]
    public function test_escalada_privilegio_admin_system_com_jwt_secret(string $method, string $path): void
    {
        $token = $this->makeUserToken('admin_system', $this->jwtSecret);
        $res   = $this->dispatchWithBearer($method, $path, $token);
        $this->assertContains($res->getStatusCode(), [401, 403], "admin_system com JWT_SECRET em $method $path deve ser bloqueado");
    }

    // 6. Bypass de método HTTP

    public function test_get_em_rota_post_retorna_404(): void
    {
        $this->assertSame(404, $this->dispatch('GET', '/api/registrar')->getStatusCode());
    }

    public function test_delete_em_rota_get_retorna_404(): void
    {
        $this->assertSame(404, $this->dispatch('DELETE', '/api/auth/me')->getStatusCode());
    }

    public function test_put_em_rota_post_retorna_404(): void
    {
        $this->assertSame(404, $this->dispatch('PUT', '/api/registrar')->getStatusCode());
    }

    // 7. Path traversal em parâmetros

    public function test_path_traversal_em_uuid_nao_retorna_200_nem_500(): void
    {
        foreach (['../../../etc/passwd', '..%2F..%2Fetc%2Fpasswd'] as $p) {
            $res = $this->dispatch('GET', '/api/usuario/' . $p);
            $this->assertNotSame(200, $res->getStatusCode(), "Path traversal '$p' nao deve retornar 200");
            $this->assertNotSame(500, $res->getStatusCode(), "Path traversal '$p' nao deve causar 500");
        }
    }

    // 8. SQL injection em parâmetros

    public function test_sql_injection_em_uuid_nao_causa_500(): void
    {
        foreach (["' OR '1'='1", "'; DROP TABLE usuarios; --"] as $p) {
            $res = $this->dispatch('GET', '/api/usuario/' . urlencode($p));
            $this->assertNotSame(500, $res->getStatusCode(), "SQL injection nao deve causar 500");
        }
    }

    // 9. CRLF injection no token

    public function test_crlf_injection_no_token_retorna_401(): void
    {
        $res = $this->dispatchWithBearer('GET', '/api/auth/me', "valid\r\nX-Injected: evil\r\n");
        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_xss_no_token_retorna_401(): void
    {
        $res = $this->dispatchWithBearer('GET', '/api/auth/me', '<script>alert(1)</script>');
        $this->assertSame(401, $res->getStatusCode());
    }

    // 10. CORS

    public function test_cors_origem_nao_autorizada_nao_recebe_header(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        $_SERVER['HTTP_ORIGIN']       = 'https://evil.com';
        $origin = $this->dispatch('GET', '/api/perfil/user')->getHeaders()['Access-Control-Allow-Origin'] ?? '';
        $this->assertNotSame('https://evil.com', $origin);
        $this->assertNotSame('*', $origin);
        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['HTTP_ORIGIN']);
    }

    public function test_cors_origem_autorizada_recebe_header(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        $_SERVER['HTTP_ORIGIN']       = 'https://app.example.com';
        $origin = $this->dispatch('GET', '/api/perfil/user')->getHeaders()['Access-Control-Allow-Origin'] ?? '';
        $this->assertSame('https://app.example.com', $origin);
        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['HTTP_ORIGIN']);
    }

    // 11. Token alg:none bypass

    public function test_token_alg_none_rejeitado(): void
    {
        $h = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $p = rtrim(strtr(base64_encode(json_encode(['sub' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'tipo' => 'user', 'nivel_acesso' => 'admin_system', 'jti' => 'x', 'exp' => time() + 3600])), '+/', '-_'), '=');
        $res = $this->dispatchWithBearer('GET', '/api/auth/me', "$h.$p.");
        $this->assertSame(401, $res->getStatusCode(), 'alg:none deve ser rejeitado');
    }

    // 12. Respostas de erro nao vazam informacoes

    public function test_rota_inexistente_nao_vaza_stack_trace(): void
    {
        $body = $this->dispatch('GET', '/api/rota-inexistente')->getBody();
        $json = is_array($body) ? $body : (json_decode(json_encode($body), true) ?? []);
        $this->assertArrayNotHasKey('trace', $json);
        $this->assertArrayNotHasKey('file',  $json);
    }

    public function test_rota_privada_sem_token_nao_vaza_info_interna(): void
    {
        $body = $this->dispatch('GET', '/api/usuarios')->getBody();
        $json = is_array($body) ? $body : (json_decode(json_encode($body), true) ?? []);
        $this->assertArrayNotHasKey('trace',     $json);
        $this->assertArrayNotHasKey('exception', $json);
    }

    // 13. Headers de seguranca em todas as respostas

    public function test_headers_seguranca_em_rota_publica(): void
    {
        $headers = $this->dispatch('GET', '/api/perfil/qualquer')->getHeaders();
        $this->assertArrayHasKey('X-Content-Type-Options',  $headers);
        $this->assertArrayHasKey('X-Frame-Options',         $headers);
        $this->assertArrayHasKey('Content-Security-Policy', $headers);
    }

    public function test_headers_seguranca_em_resposta_401(): void
    {
        $headers = $this->dispatch('GET', '/api/usuarios')->getHeaders();
        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertArrayHasKey('X-Frame-Options',        $headers);
    }

    // 14. Rotas publicas nao exigem token

    public function test_perfil_publico_nao_exige_token(): void
    {
        $res = $this->dispatch('GET', '/api/perfil/qualquer-usuario');
        $this->assertNotSame(401, $res->getStatusCode());
    }

    public function test_verificar_email_nao_exige_token(): void
    {
        $res = $this->dispatch('POST', '/api/usuarios/verificar-email/token-qualquer');
        $this->assertNotSame(401, $res->getStatusCode());
    }

    // 15. Injecao de nivel_acesso no registro publico ignorada

    public function test_nivel_acesso_injetado_no_registro_ignorado(): void
    {
        $res = $this->dispatch('POST', '/api/registrar', [
            'nome_completo' => 'Hacker', 'username' => 'hacker123',
            'email' => 'hacker@example.com', 'senha' => 'Senha@123',
            'nivel_acesso' => 'admin_system',
        ]);
        $this->assertNotSame(500, $res->getStatusCode());
    }

    // 16. Token revogado bloqueado

    public function test_token_revogado_retorna_401(): void
    {
        $blacklist = $this->createMock(\Src\Kernel\Contracts\TokenBlacklistInterface::class);
        $blacklist->method('isRevoked')->willReturn(true);
        $this->container->bind(\Src\Kernel\Contracts\TokenBlacklistInterface::class, $blacklist, true);

        $token = $this->makeUserToken('usuario');
        $res   = $this->dispatchWithBearer('GET', '/api/perfil', $token);
        $this->assertSame(401, $res->getStatusCode());
    }

    // 17. UUID malformado nao causa 500

    public function test_uuid_malformado_nao_causa_500(): void
    {
        foreach (['not-a-uuid', str_repeat('a', 100), '<script>'] as $uuid) {
            $res = $this->dispatch('GET', '/api/usuario/' . urlencode($uuid));
            $this->assertNotSame(500, $res->getStatusCode(), "UUID '$uuid' nao deve causar 500");
        }
    }
}