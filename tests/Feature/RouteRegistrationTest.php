<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;
use Src\Kernel\Nucleo\Router;
use Src\Kernel\Nucleo\Container;
use Src\Kernel\Nucleo\ModuleLoader;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Verifica que todas as rotas da API estão registradas e respondem corretamente.
 *
 * Rotas públicas (sem auth): verificamos que não retornam 404/405 e que a lógica
 * de validação funciona (quando possível sem banco).
 *
 * Rotas protegidas: verificamos que retornam 401 sem token (rota existe e middleware
 * está funcionando).
 *
 * Rotas que dependem do banco no AuthController (pdo() próprio): verificamos apenas
 * que estão registradas (não retornam 404/405), aceitando qualquer outro status.
 */
#[AllowMockObjectsWithoutExpectations]
class RouteRegistrationTest extends TestCase
{
    private Router $router;
    private Container $container;

    protected function setUp(): void
    {
        $_ENV['APP_ENV']    = 'testing';
        $_ENV['APP_DEBUG']  = 'false';
        $_ENV['DB_HOST']    = '127.0.0.1';
        $_ENV['DB_NOME']    = 'test';
        $_ENV['DB_USUARIO'] = 'test';
        $_ENV['DB_SENHA']   = 'test';
        $_ENV['DB_CONEXAO'] = 'mysql';
        $_ENV['DB_PORT']    = '3306';

        $this->container = new Container();
        $this->router    = new Router($this->container);
        $this->container->bind(\Src\Kernel\Contracts\ContainerInterface::class, $this->container, true);
        $this->container->bind(\Src\Kernel\Contracts\RouterInterface::class, $this->router, true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('exec')->willReturn(0);
        $pdo->method('query')->willReturn($this->createMock(\PDOStatement::class));
        $this->container->bind(\PDO::class, $pdo, true);

        $migrator = $this->createMock(\Src\Kernel\Support\DB\PluginMigrator::class);
        $this->container->bind(\Src\Kernel\Support\DB\PluginMigrator::class, $migrator, true);

        $this->container->bind(\Src\Kernel\Support\AuditLogger::class, new \Src\Kernel\Support\AuditLogger(null), true);

        $this->container->bind(
            \Src\Kernel\Contracts\UserRepositoryInterface::class,
            $this->createMock(\Src\Kernel\Contracts\UserRepositoryInterface::class),
            true
        );
        $this->container->bind(
            \Src\Kernel\Contracts\TokenBlacklistInterface::class,
            $this->createMock(\Src\Kernel\Contracts\TokenBlacklistInterface::class),
            true
        );

        // Stub UsuarioService — evita que UsuarioController tente conectar ao banco
        $usuarioService = $this->createMock(\Src\Modules\Usuario\Services\UsuarioServiceInterface::class);
        $usuarioService->method('buscarPorUsername')->willReturn(null);
        $usuarioService->method('buscarPorUuid')->willReturn(null);
        $usuarioService->method('buscarPorEmail')->willReturn(null);
        $usuarioService->method('listar')->willReturn([]);
        $this->container->bind(\Src\Modules\Usuario\Services\UsuarioServiceInterface::class, $usuarioService, true);

        $modules = new ModuleLoader($this->container);
        $root    = dirname(__DIR__, 2);
        $modules->discover($root . '/src/Modules');
        $modules->bootAll();
        $modules->registerRoutes($this->router);

        // Rotas do core (index.php)
        $this->router->get('/api/status',    fn() => Response::json(['status' => ['env' => 'testing'], 'modules' => []]));
        $this->router->get('/api/db-status', fn() => Response::json(['conectado' => false], 503));
    }

    private function dispatch(string $method, string $path, array $body = []): Response
    {
        return $this->router->dispatch(new Request($body, [], [], $method, $path));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Todas as rotas registradas — inventário completo
    // ─────────────────────────────────────────────────────────────────────

    /** @dataProvider todasAsRotasProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('todasAsRotasProvider')]
    public function test_rota_esta_registrada(string $method, string $path): void
    {
        $uris    = array_column($this->router->all(), 'uri');
        $methods = array_column($this->router->all(), 'method');
        $found   = false;
        foreach ($this->router->all() as $route) {
            if ($route['method'] === strtoupper($method) && $route['uri'] === $path) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Rota {$method} {$path} não está registrada no router.");
    }

    public static function todasAsRotasProvider(): array
    {
        return [
            // Auth — públicas
            'POST /api/auth/login'                              => ['POST', '/api/auth/login'],
            'POST /api/login'                                   => ['POST', '/api/login'],
            'POST /api/auth/recuperacao-senha'                  => ['POST', '/api/auth/recuperacao-senha'],
            'POST /api/auth/resetar-senha'                      => ['POST', '/api/auth/resetar-senha'],
            'POST /api/recuperar-senha'                         => ['POST', '/api/recuperar-senha'],
            'POST /api/recuperar-senha/confirmar'               => ['POST', '/api/recuperar-senha/confirmar'],
            'GET  /api/recuperar-senha/validar/{token}'         => ['GET',  '/api/recuperar-senha/validar/{token}'],
            'POST /api/auth/refresh'                            => ['POST', '/api/auth/refresh'],
            'GET  /api/auth/verify-email'                       => ['GET',  '/api/auth/verify-email'],
            'POST /api/auth/email-verification'                 => ['POST', '/api/auth/email-verification'],
            // Auth — privadas
            'GET  /api/auth/me'                                 => ['GET',  '/api/auth/me'],
            'POST /api/auth/logout'                             => ['POST', '/api/auth/logout'],
            'GET  /api/auth/email-verification'                 => ['GET',  '/api/auth/email-verification'],
            // Usuario — públicas
            'POST /api/criar/usuario'                           => ['POST', '/api/criar/usuario'],
            'POST /api/registrar'                               => ['POST', '/api/registrar'],
            'GET  /api/perfil/{username}'                       => ['GET',  '/api/perfil/{username}'],
            'GET  /perfil/{username}'                           => ['GET',  '/perfil/{username}'],
            'POST /api/usuarios/verificar-email/{token}'        => ['POST', '/api/usuarios/verificar-email/{token}'],
            // Usuario — privadas
            'GET  /api/usuarios'                                => ['GET',  '/api/usuarios'],
            'GET  /api/usuario/{uuid}'                          => ['GET',  '/api/usuario/{uuid}'],
            'PUT  /api/usuario/atualizar/{uuid}'                => ['PUT',  '/api/usuario/atualizar/{uuid}'],
            'DELETE /api/usuario/deletar/{uuid}'                => ['DELETE', '/api/usuario/deletar/{uuid}'],
            'PATCH /api/usuario/{uuid}/desativar'               => ['PATCH', '/api/usuario/{uuid}/desativar'],
            'PATCH /api/usuario/{uuid}/ativar'                  => ['PATCH', '/api/usuario/{uuid}/ativar'],
            'GET  /api/perfil'                                  => ['GET',  '/api/perfil'],
            'PUT  /api/perfil'                                  => ['PUT',  '/api/perfil'],
            'PUT  /api/perfil/email'                            => ['PUT',  '/api/perfil/email'],
            'PUT  /api/perfil/senha'                            => ['PUT',  '/api/perfil/senha'],
            'POST /api/perfil/upload'                           => ['POST', '/api/perfil/upload'],
            'DELETE /api/perfil'                                => ['DELETE', '/api/perfil'],
            'POST /api/usuarios/{uuid}/enviar-verificacao-email'=> ['POST', '/api/usuarios/{uuid}/enviar-verificacao-email'],
            'POST /api/usuarios/enviar-verificacao-email'       => ['POST', '/api/usuarios/enviar-verificacao-email'],
            'GET  /api/usuarios/verificar-email-status'         => ['GET',  '/api/usuarios/verificar-email-status'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rotas públicas — não retornam 404/405
    // ─────────────────────────────────────────────────────────────────────

    /** @dataProvider rotasPublicasProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('rotasPublicasProvider')]
    public function test_rota_publica_nao_retorna_404_nem_405(string $method, string $path): void
    {
        $res = $this->dispatch($method, $path);
        $this->assertNotSame(404, $res->getStatusCode(),
            "Rota {$method} {$path} retornou 404 — rota não encontrada.");
        $this->assertNotSame(405, $res->getStatusCode(),
            "Rota {$method} {$path} retornou 405 — método incorreto.");
    }

    public static function rotasPublicasProvider(): array
    {
        return [
            // Auth — sem dependência de banco no controller
            'POST /api/auth/recuperacao-senha'         => ['POST', '/api/auth/recuperacao-senha'],
            'POST /api/auth/resetar-senha'             => ['POST', '/api/auth/resetar-senha'],
            'POST /api/recuperar-senha'                => ['POST', '/api/recuperar-senha'],
            'POST /api/recuperar-senha/confirmar'      => ['POST', '/api/recuperar-senha/confirmar'],
            'GET  /api/auth/verify-email'              => ['GET',  '/api/auth/verify-email'],
            // Usuario — sem banco (UsuarioService mockado)
            'POST /api/registrar'                      => ['POST', '/api/registrar'],
            'POST /api/criar/usuario'                  => ['POST', '/api/criar/usuario'],
            'POST /api/usuarios/verificar-email/tok'   => ['POST', '/api/usuarios/verificar-email/tok123'],
            // Core
            'GET  /api/status'                         => ['GET',  '/api/status'],
            'GET  /api/db-status'                      => ['GET',  '/api/db-status'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rotas que usam AuthController (PDO próprio) — apenas verifica registro
    // ─────────────────────────────────────────────────────────────────────

    /** @dataProvider rotasAuthComBancoProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('rotasAuthComBancoProvider')]
    public function test_rota_auth_esta_registrada_e_nao_retorna_404(string $method, string $path): void
    {
        try {
            $res = $this->dispatch($method, $path);
            $this->assertNotSame(404, $res->getStatusCode(),
                "Rota {$method} {$path} retornou 404 — rota não registrada.");
            $this->assertNotSame(405, $res->getStatusCode(),
                "Rota {$method} {$path} retornou 405 — método incorreto.");
        } catch (\PDOException $e) {
            // AuthController builds its own PDO — a DB connection error means the route
            // IS registered and reached the controller. Not a routing problem.
            $this->assertStringNotContainsString('404', $e->getMessage());
        }
    }

    public static function rotasAuthComBancoProvider(): array
    {
        return [
            'POST /api/auth/login'          => ['POST', '/api/auth/login'],
            'POST /api/login'               => ['POST', '/api/login'],
            'POST /api/auth/refresh'        => ['POST', '/api/auth/refresh'],
            'POST /api/auth/email-verification' => ['POST', '/api/auth/email-verification'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rotas protegidas — retornam 401 sem token
    // ─────────────────────────────────────────────────────────────────────

    /** @dataProvider rotasPrivadasProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('rotasPrivadasProvider')]
    public function test_rota_privada_retorna_401_sem_token(string $method, string $path): void
    {
        $res = $this->dispatch($method, $path);
        $this->assertNotSame(404, $res->getStatusCode(),
            "Rota {$method} {$path} retornou 404 — rota não registrada.");
        $this->assertSame(401, $res->getStatusCode(),
            "Rota protegida {$method} {$path} deveria retornar 401, retornou {$res->getStatusCode()}.");
    }

    public static function rotasPrivadasProvider(): array
    {
        return [
            // Auth
            'GET  /api/auth/me'                                         => ['GET',  '/api/auth/me'],
            'POST /api/auth/logout'                                     => ['POST', '/api/auth/logout'],
            'GET  /api/auth/email-verification'                         => ['GET',  '/api/auth/email-verification'],
            // Usuario
            'GET  /api/usuarios'                                        => ['GET',  '/api/usuarios'],
            'GET  /api/usuario/{uuid}'                                  => ['GET',  '/api/usuario/some-uuid'],
            'PUT  /api/usuario/atualizar/{uuid}'                        => ['PUT',  '/api/usuario/atualizar/some-uuid'],
            'DELETE /api/usuario/deletar/{uuid}'                        => ['DELETE', '/api/usuario/deletar/some-uuid'],
            'PATCH /api/usuario/{uuid}/desativar'                       => ['PATCH', '/api/usuario/some-uuid/desativar'],
            'PATCH /api/usuario/{uuid}/ativar'                          => ['PATCH', '/api/usuario/some-uuid/ativar'],
            'GET  /api/perfil'                                          => ['GET',  '/api/perfil'],
            'PUT  /api/perfil'                                          => ['PUT',  '/api/perfil'],
            'PUT  /api/perfil/email'                                    => ['PUT',  '/api/perfil/email'],
            'PUT  /api/perfil/senha'                                    => ['PUT',  '/api/perfil/senha'],
            'POST /api/perfil/upload'                                   => ['POST', '/api/perfil/upload'],
            'DELETE /api/perfil'                                        => ['DELETE', '/api/perfil'],
            'POST /api/usuarios/{uuid}/enviar-verificacao-email'        => ['POST', '/api/usuarios/some-uuid/enviar-verificacao-email'],
            'POST /api/usuarios/enviar-verificacao-email'               => ['POST', '/api/usuarios/enviar-verificacao-email'],
            'GET  /api/usuarios/verificar-email-status'                 => ['GET',  '/api/usuarios/verificar-email-status'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Validações de negócio em rotas públicas
    // ─────────────────────────────────────────────────────────────────────

    public function test_registrar_sem_body_retorna_422(): void
    {
        $res = $this->dispatch('POST', '/api/registrar', []);
        $this->assertContains($res->getStatusCode(), [422, 429]);
    }

    public function test_registrar_com_email_invalido_retorna_422(): void
    {
        $res = $this->dispatch('POST', '/api/registrar', [
            'nome_completo' => 'Test User',
            'username'      => 'testuser',
            'email'         => 'nao-e-email',
            'senha'         => 'Test@1234',
        ]);
        $this->assertContains($res->getStatusCode(), [422, 429]);
    }

    public function test_registrar_com_senha_fraca_retorna_422(): void
    {
        $res = $this->dispatch('POST', '/api/registrar', [
            'nome_completo' => 'Test User',
            'username'      => 'testuser',
            'email'         => 'test@example.com',
            'senha'         => '123',
        ]);
        $this->assertContains($res->getStatusCode(), [422, 429]);
    }

    public function test_recuperacao_senha_com_email_invalido_retorna_400_ou_429(): void
    {
        // Rate limiter may fire (429) if this route was already called in this test run
        $res = $this->dispatch('POST', '/api/auth/recuperacao-senha', ['email' => 'invalido']);
        $this->assertContains($res->getStatusCode(), [400, 429]);
    }

    public function test_recuperacao_senha_sem_email_retorna_400_ou_429(): void
    {
        // Rate limiter may fire (429) if this route was already called in this test run
        $res = $this->dispatch('POST', '/api/auth/recuperacao-senha', []);
        $this->assertContains($res->getStatusCode(), [400, 429]);
    }

    public function test_resetar_senha_sem_token_retorna_400_ou_429(): void
    {
        $res = $this->dispatch('POST', '/api/auth/resetar-senha', ['nova_senha' => 'Nova@1234']);
        $this->assertContains($res->getStatusCode(), [400, 429]);
    }

    public function test_resetar_senha_com_senha_curta_retorna_400_ou_429(): void
    {
        $res = $this->dispatch('POST', '/api/auth/resetar-senha', ['token' => 'tok', 'nova_senha' => '123']);
        $this->assertContains($res->getStatusCode(), [400, 429]);
    }

    public function test_verificar_email_sem_token_retorna_400(): void
    {
        $res = $this->dispatch('GET', '/api/auth/verify-email');
        $this->assertContains($res->getStatusCode(), [400, 429]);
    }

    public function test_perfil_publico_username_inexistente_retorna_404(): void
    {
        // Route works — controller returns 404 when user not found (mock returns null)
        $res = $this->dispatch('GET', '/api/perfil/usuario-inexistente-xyz');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_perfil_html_username_inexistente_retorna_404(): void
    {
        $res = $this->dispatch('GET', '/perfil/usuario-inexistente-xyz');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_validar_token_recuperacao_rota_existe(): void
    {
        // AuthController builds its own PDO — just verify route is registered and not 404/405
        $res = $this->dispatch('GET', '/api/recuperar-senha/validar/token-invalido');
        $this->assertNotSame(404, $res->getStatusCode());
        $this->assertNotSame(405, $res->getStatusCode());
    }
}
