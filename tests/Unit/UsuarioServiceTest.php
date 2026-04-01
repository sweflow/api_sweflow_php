<?php

namespace Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use Src\Modules\Usuario\Services\UsuarioService;
use Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface;
use Src\Modules\Usuario\Entities\Usuario;
use DomainException;

class UsuarioServiceTest extends TestCase
{
    /** @return MockObject&UsuarioRepositoryInterface */
    private function makeRepo(): MockObject
    {
        return $this->createMock(UsuarioRepositoryInterface::class);
    }

    private function makeUsuario(): Usuario
    {
        return Usuario::registrar('Test User', 'testuser', 'test@example.com', 'Test@1234');
    }

    public function test_criar_chama_salvar_quando_email_e_username_livres(): void
    {
        $repo = $this->makeRepo();
        $repo->method('emailExiste')->willReturn(false);
        $repo->method('usernameExiste')->willReturn(false);
        $repo->expects($this->once())->method('salvar');

        $service = new UsuarioService($repo);
        $service->criar($this->makeUsuario());
    }

    public function test_criar_lanca_excecao_quando_email_ja_existe(): void
    {
        $repo = $this->makeRepo();
        $repo->method('emailExiste')->willReturn(true);

        $service = new UsuarioService($repo);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('E-mail já cadastrado.');
        $service->criar($this->makeUsuario());
    }

    public function test_criar_lanca_excecao_quando_username_ja_existe(): void
    {
        $repo = $this->makeRepo();
        $repo->method('emailExiste')->willReturn(false);
        $repo->method('usernameExiste')->willReturn(true);

        $service = new UsuarioService($repo);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Username já cadastrado.');
        $service->criar($this->makeUsuario());
    }

    public function test_buscar_por_uuid_retorna_usuario(): void
    {
        $usuario = $this->makeUsuario();
        $repo    = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);

        $service = new UsuarioService($repo);
        $result  = $service->buscarPorUuid('some-uuid');
        $this->assertSame($usuario, $result);
    }

    public function test_buscar_por_uuid_retorna_null_quando_nao_encontrado(): void
    {
        $repo = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn(null);

        $service = new UsuarioService($repo);
        $this->assertNull($service->buscarPorUuid('nao-existe'));
    }

    public function test_desativar_lanca_excecao_quando_usuario_nao_existe(): void
    {
        $repo = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn(null);

        $service = new UsuarioService($repo);
        $this->expectException(DomainException::class);
        $service->desativar('uuid-x');
    }

    public function test_desativar_chama_salvar(): void
    {
        $usuario = $this->makeUsuario();
        $repo    = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);
        $repo->expects($this->once())->method('salvar');

        $service = new UsuarioService($repo);
        $service->desativar($usuario->getUuid()->toString());
        $this->assertFalse($usuario->isAtivo());
    }

    public function test_ativar_chama_salvar(): void
    {
        $usuario = $this->makeUsuario();
        $usuario->desativar();
        $repo = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);
        $repo->expects($this->once())->method('salvar');

        $service = new UsuarioService($repo);
        $service->ativar($usuario->getUuid()->toString());
        $this->assertTrue($usuario->isAtivo());
    }

    public function test_deletar_lanca_excecao_quando_usuario_nao_existe(): void
    {
        $repo = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn(null);

        $service = new UsuarioService($repo);
        $this->expectException(DomainException::class);
        $service->deletar('uuid-x');
    }

    public function test_deletar_chama_deletar_no_repo(): void
    {
        $usuario = $this->makeUsuario();
        $repo    = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);
        $repo->expects($this->once())->method('deletar');

        $service = new UsuarioService($repo);
        $service->deletar($usuario->getUuid()->toString());
    }

    public function test_verificar_senha_retorna_true_para_senha_correta(): void
    {
        $usuario = $this->makeUsuario();
        $repo    = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);

        $service = new UsuarioService($repo);
        $this->assertTrue($service->verificarSenha($usuario->getUuid()->toString(), 'Test@1234'));
    }

    public function test_verificar_senha_retorna_false_para_senha_errada(): void
    {
        $usuario = $this->makeUsuario();
        $repo    = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);

        $service = new UsuarioService($repo);
        $this->assertFalse($service->verificarSenha($usuario->getUuid()->toString(), 'errada'));
    }

    public function test_alterar_senha_chama_salvar(): void
    {
        $usuario = $this->makeUsuario();
        $repo    = $this->makeRepo();
        $repo->method('buscarPorUuid')->willReturn($usuario);
        $repo->expects($this->once())->method('salvar');

        $service = new UsuarioService($repo);
        $service->alterarSenha($usuario->getUuid()->toString(), 'Nova@Senh1');
    }

    public function test_listar_delega_para_repo(): void
    {
        $repo = $this->makeRepo();
        $repo->method('buscarPorNomePaginado')->willReturn([$this->makeUsuario()]);

        $service = new UsuarioService($repo);
        $result  = $service->listar(1, 10);
        $this->assertCount(1, $result);
    }
}
