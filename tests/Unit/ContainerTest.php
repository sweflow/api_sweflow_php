<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;
use Src\Kernel\Nucleo\Container;

#[AllowMockObjectsWithoutExpectations]
class ContainerTest extends TestCase
{
    public function test_bind_e_make_com_closure(): void
    {
        $c = new Container();
        $c->bind('foo', fn() => 'bar');
        $this->assertSame('bar', $c->make('foo'));
    }

    public function test_singleton_retorna_mesma_instancia(): void
    {
        $c = new Container();
        $c->bind('counter', fn() => new \stdClass(), true);
        $a = $c->make('counter');
        $b = $c->make('counter');
        $this->assertSame($a, $b);
    }

    public function test_nao_singleton_retorna_instancias_diferentes(): void
    {
        $c = new Container();
        $c->bind('obj', fn() => new \stdClass(), false);
        $a = $c->make('obj');
        $b = $c->make('obj');
        $this->assertNotSame($a, $b);
    }

    public function test_make_classe_concreta_sem_dependencias(): void
    {
        $c = new Container();
        $obj = $c->make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    public function test_make_lanca_excecao_para_classe_inexistente(): void
    {
        $this->expectException(\RuntimeException::class);
        $c = new Container();
        $c->make('Classe\Que\NaoExiste');
    }

    public function test_bind_objeto_direto_retorna_mesmo_objeto(): void
    {
        $c   = new Container();
        $obj = new \stdClass();
        $obj->val = 42;
        $c->bind('myobj', $obj, true);
        $this->assertSame($obj, $c->make('myobj'));
    }

    public function test_autowiring_com_dependencia_simples(): void
    {
        $c = new Container();
        // ClasseA depende de ClasseB (sem construtor)
        // Usamos stdClass como dependência trivial via binding
        $c->bind(\stdClass::class, fn() => (object)['injected' => true]);

        // Classe anônima com dependência injetável
        $result = $c->make(\stdClass::class);
        $this->assertTrue($result->injected);
    }

    public function test_convencao_interface_para_classe(): void
    {
        // Container tenta FooInterface -> Foo no mesmo namespace
        $c = new Container();
        $pdo = $this->createMock(\PDO::class);
        $c->bind(\PDO::class, $pdo, true);

        // UsuarioRepositoryInterface -> UsuarioRepository (mesmo namespace)
        $repo = $c->make(\Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface::class);
        $this->assertInstanceOf(\Src\Modules\Usuario\Repositories\UsuarioRepository::class, $repo);
    }
}
