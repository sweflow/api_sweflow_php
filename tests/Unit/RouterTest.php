<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Nucleo\Router;
use Src\Kernel\Nucleo\Container;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class RouterTest extends TestCase
{
    private function makeRouter(): Router
    {
        return new Router(new Container());
    }

    private function req(string $method, string $path): Request
    {
        return new Request([], [], [], $method, $path);
    }

    public function test_rota_get_simples_e_despachada(): void
    {
        $router = $this->makeRouter();
        $router->get('/api/ping', fn() => Response::json(['pong' => true]));
        $res = $router->dispatch($this->req('GET', '/api/ping'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(true, $res->getBody()['pong']);
    }

    public function test_rota_post_simples_e_despachada(): void
    {
        $router = $this->makeRouter();
        $router->post('/api/echo', fn($req) => Response::json(['ok' => true]));
        $res = $router->dispatch($this->req('POST', '/api/echo'));
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_rota_com_parametro_dinamico(): void
    {
        $router = $this->makeRouter();
        $router->get('/api/usuario/{uuid}', fn($req, $uuid) => Response::json(['uuid' => $uuid]));
        $res = $router->dispatch($this->req('GET', '/api/usuario/abc-123'));
        $this->assertSame('abc-123', $res->getBody()['uuid']);
    }

    public function test_rota_nao_encontrada_retorna_404(): void
    {
        $router = $this->makeRouter();
        $res = $router->dispatch($this->req('GET', '/nao-existe'));
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_metodo_errado_retorna_404_pois_router_nao_suporta_405(): void
    {
        $router = $this->makeRouter();
        $router->get('/api/only-get', fn() => Response::json([]));
        // Router returns 404 for wrong method (no 405 support by design)
        $res = $router->dispatch($this->req('POST', '/api/only-get'));
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_rota_put_e_despachada(): void
    {
        $router = $this->makeRouter();
        $router->put('/api/item/{id}', fn($req, $id) => Response::json(['id' => $id]));
        $res = $router->dispatch($this->req('PUT', '/api/item/42'));
        $this->assertSame('42', $res->getBody()['id']);
    }

    public function test_rota_delete_e_despachada(): void
    {
        $router = $this->makeRouter();
        $router->delete('/api/item/{id}', fn($req, $id) => Response::json(['deleted' => $id]));
        $res = $router->dispatch($this->req('DELETE', '/api/item/99'));
        $this->assertSame('99', $res->getBody()['deleted']);
    }

    public function test_rota_patch_e_despachada(): void
    {
        $router = $this->makeRouter();
        $router->patch('/api/item/{id}/status', fn($req, $id) => Response::json(['patched' => $id]));
        $res = $router->dispatch($this->req('PATCH', '/api/item/7/status'));
        $this->assertSame('7', $res->getBody()['patched']);
    }

    public function test_middleware_e_executado_antes_do_handler(): void
    {
        $router = $this->makeRouter();
        $order  = [];

        $mw = new class($order) implements \Src\Kernel\Contracts\MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function handle(\Src\Kernel\Http\Request\Request $req, callable $next): Response {
                $this->order[] = 'middleware';
                return $next($req);
            }
        };

        $router->get('/api/mw-test', function () use (&$order) {
            $order[] = 'handler';
            return Response::json(['ok' => true]);
        }, [get_class($mw)]);

        // Bind the anonymous class so the container can resolve it
        $container = new Container();
        $container->bind(get_class($mw), $mw, true);
        $router2 = new Router($container);
        $router2->get('/api/mw-test2', function () use (&$order) {
            $order[] = 'handler';
            return Response::json(['ok' => true]);
        }, [get_class($mw)]);

        $router2->dispatch($this->req('GET', '/api/mw-test2'));
        $this->assertSame(['middleware', 'handler'], $order);
    }

    public function test_all_retorna_todas_as_rotas_registradas(): void
    {
        $router = $this->makeRouter();
        $router->get('/a', fn() => Response::json([]));
        $router->post('/b', fn() => Response::json([]));
        $this->assertCount(2, $router->all());
    }
}
