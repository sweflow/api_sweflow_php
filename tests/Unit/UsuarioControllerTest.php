<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Modules\Usuario\Controllers\UsuarioController;
use Src\Modules\Usuario\Services\UsuarioServiceInterface;
use Src\Modules\Usuario\Entities\Usuario;

class UsuarioControllerTest extends TestCase
{
    private function makeService(array $methods = []): UsuarioServiceInterface
    {
        return $this->createMock(UsuarioServiceInterface::class);
    }

    private function makeController(?UsuarioServiceInterface $service = null): UsuarioController
    {
        return new UsuarioController($service ?? $this->makeService());
    }

    private function makeUsuario(): Usuario
    {
        return Usuario::registrar('Test User', 'testuser', 'test@example.com', 'Test@1234');
    }

    // ── criar ─────────────────────────────────────────────────────────────

    public function test_criar_retorna_422_sem_campos_obrigatorios(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('POST', '/api/registrar', []);
        $res  = $ctrl->criar($req);
        $this->assertStatusCode($res, 422);
        $this->assertJsonStatus($res, 'error');
    }

    public function test_criar_retorna_422_com_email_invalido(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('POST', '/api/registrar', [
            'nome_completo' => 'Test',
            'username'      => 'testuser',
            'email'         => 'invalido',
            'senha'         => 'Test@1234',
        ]);
        $res = $ctrl->criar($req);
        $this->assertStatusCode($res, 422);
    }

    public function test_criar_retorna_201_com_dados_validos(): void
    {
        $service = $this->makeService();
        $service->expects($this->once())->method('criar');
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('POST', '/api/registrar', [
            'nome_completo' => 'Test User',
            'username'      => 'testuser2',
            'email'         => 'test2@example.com',
            'senha'         => 'Test@1234',
        ]);
        $res = $ctrl->criar($req);
        $this->assertStatusCode($res, 201);
        $this->assertJsonStatus($res, 'success');
    }

    public function test_criar_retorna_422_quando_service_lanca_domain_exception(): void
    {
        $service = $this->makeService();
        $service->method('criar')->willThrowException(new \DomainException('E-mail já cadastrado.'));
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('POST', '/api/registrar', [
            'nome_completo' => 'Test User',
            'username'      => 'testuser3',
            'email'         => 'dup@example.com',
            'senha'         => 'Test@1234',
        ]);
        $res = $ctrl->criar($req);
        $this->assertStatusCode($res, 422);
        $this->assertJsonStatus($res, 'error');
    }

    // ── listar ────────────────────────────────────────────────────────────

    public function test_listar_retorna_lista_de_usuarios(): void
    {
        $service = $this->makeService();
        $service->method('listar')->willReturn([$this->makeUsuario()]);
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('GET', '/api/usuarios');
        $res  = $ctrl->listar($req);
        $this->assertStatusCode($res, 200);
        $body = $res->getBody();
        $this->assertSame('success', $body['status']);
        $this->assertCount(1, $body['usuarios']);
    }

    public function test_listar_retorna_lista_vazia(): void
    {
        $service = $this->makeService();
        $service->method('listar')->willReturn([]);
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('GET', '/api/usuarios');
        $res  = $ctrl->listar($req);
        $this->assertStatusCode($res, 200);
        $this->assertCount(0, $res->getBody()['usuarios']);
    }

    // ── buscar ────────────────────────────────────────────────────────────

    public function test_buscar_retorna_404_quando_nao_encontrado(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUuid')->willReturn(null);
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('GET', '/api/usuario/uuid-inexistente');
        $res  = $ctrl->buscar($req, 'uuid-inexistente');
        $this->assertStatusCode($res, 404);
    }

    public function test_buscar_retorna_usuario_encontrado(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUuid')->willReturn($this->makeUsuario());
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('GET', '/api/usuario/some-uuid');
        $res  = $ctrl->buscar($req, 'some-uuid');
        $this->assertStatusCode($res, 200);
        $this->assertJsonStatus($res, 'success');
    }

    // ── atualizar ─────────────────────────────────────────────────────────

    public function test_atualizar_retorna_422_sem_body(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('PUT', '/api/usuario/atualizar/uuid', []);
        $res  = $ctrl->atualizar($req, 'uuid');
        $this->assertStatusCode($res, 422);
    }

    public function test_atualizar_retorna_sucesso(): void
    {
        $service = $this->makeService();
        $service->expects($this->once())->method('atualizar');
        $service->method('buscarPorUuid')->willReturn($this->makeUsuario());
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('PUT', '/api/usuario/atualizar/uuid', ['nome_completo' => 'Novo Nome']);
        $res  = $ctrl->atualizar($req, 'uuid');
        $this->assertStatusCode($res, 200);
        $this->assertJsonStatus($res, 'success');
    }

    // ── deletar ───────────────────────────────────────────────────────────

    public function test_deletar_retorna_sucesso(): void
    {
        $service = $this->makeService();
        $service->expects($this->once())->method('deletar');
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('DELETE', '/api/usuario/deletar/uuid');
        $res  = $ctrl->deletar($req, 'uuid');
        $this->assertStatusCode($res, 200);
        $this->assertJsonStatus($res, 'success');
    }

    public function test_deletar_retorna_422_quando_nao_encontrado(): void
    {
        $service = $this->makeService();
        $service->method('deletar')->willThrowException(new \DomainException('Usuário não encontrado.'));
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('DELETE', '/api/usuario/deletar/uuid');
        $res  = $ctrl->deletar($req, 'uuid');
        $this->assertStatusCode($res, 422);
    }

    // ── desativar / ativar ────────────────────────────────────────────────

    public function test_desativar_retorna_sucesso(): void
    {
        $service = $this->makeService();
        $service->expects($this->once())->method('desativar');
        $ctrl = $this->makeController($service);
        $res  = $ctrl->desativar($this->makeRequest('PATCH', '/api/usuario/uuid/desativar'), 'uuid');
        $this->assertStatusCode($res, 200);
    }

    public function test_ativar_retorna_sucesso(): void
    {
        $service = $this->makeService();
        $service->expects($this->once())->method('ativar');
        $ctrl = $this->makeController($service);
        $res  = $ctrl->ativar($this->makeRequest('PATCH', '/api/usuario/uuid/ativar'), 'uuid');
        $this->assertStatusCode($res, 200);
    }

    // ── perfil autenticado ────────────────────────────────────────────────

    public function test_perfil_retorna_401_sem_auth_user(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('GET', '/api/perfil');
        $res  = $ctrl->perfil($req);
        $this->assertStatusCode($res, 401);
    }

    public function test_perfil_retorna_usuario_autenticado(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('GET', '/api/perfil', [], [], [], ['auth_user' => $this->makeUsuario()]);
        $res  = $ctrl->perfil($req);
        $this->assertStatusCode($res, 200);
        $this->assertJsonStatus($res, 'success');
    }

    public function test_atualizar_perfil_retorna_401_sem_auth(): void
    {
        $ctrl = $this->makeController();
        $res  = $ctrl->atualizarPerfil($this->makeRequest('PUT', '/api/perfil'));
        $this->assertStatusCode($res, 401);
    }

    public function test_atualizar_perfil_retorna_422_sem_campos_validos(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('PUT', '/api/perfil', ['nivel_acesso' => 'admin'], [], [], ['auth_user' => $this->makeUsuario()]);
        $res  = $ctrl->atualizarPerfil($req);
        $this->assertStatusCode($res, 422);
    }

    public function test_alterar_email_retorna_401_sem_auth(): void
    {
        $ctrl = $this->makeController();
        $res  = $ctrl->alterarEmail($this->makeRequest('PUT', '/api/perfil/email'));
        $this->assertStatusCode($res, 401);
    }

    public function test_alterar_email_retorna_422_sem_email(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('PUT', '/api/perfil/email', [], [], [], ['auth_user' => $this->makeUsuario()]);
        $res  = $ctrl->alterarEmail($req);
        $this->assertStatusCode($res, 422);
    }

    public function test_alterar_email_retorna_403_com_senha_errada(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('PUT', '/api/perfil/email',
            ['email' => 'novo@example.com', 'senha' => 'senhaerrada'],
            [], [], ['auth_user' => $this->makeUsuario()]
        );
        $res = $ctrl->alterarEmail($req);
        $this->assertStatusCode($res, 403);
    }

    public function test_alterar_senha_retorna_401_sem_auth(): void
    {
        $ctrl = $this->makeController();
        $res  = $ctrl->alterarSenha($this->makeRequest('PUT', '/api/perfil/senha'));
        $this->assertStatusCode($res, 401);
    }

    public function test_alterar_senha_retorna_422_sem_campos(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('PUT', '/api/perfil/senha', [], [], [], ['auth_user' => $this->makeUsuario()]);
        $res  = $ctrl->alterarSenha($req);
        $this->assertStatusCode($res, 422);
    }

    public function test_alterar_senha_retorna_403_com_senha_atual_errada(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('PUT', '/api/perfil/senha',
            ['senha_atual' => 'errada', 'nova_senha' => 'Nova@1234'],
            [], [], ['auth_user' => $this->makeUsuario()]
        );
        $res = $ctrl->alterarSenha($req);
        $this->assertStatusCode($res, 403);
    }

    public function test_deletar_minha_conta_retorna_401_sem_auth(): void
    {
        $ctrl = $this->makeController();
        $res  = $ctrl->deletarMinhaConta($this->makeRequest('DELETE', '/api/perfil'));
        $this->assertStatusCode($res, 401);
    }

    public function test_deletar_minha_conta_retorna_403_com_senha_errada(): void
    {
        $ctrl = $this->makeController();
        $req  = $this->makeRequest('DELETE', '/api/perfil',
            ['senha' => 'errada'],
            [], [], ['auth_user' => $this->makeUsuario()]
        );
        $res = $ctrl->deletarMinhaConta($req);
        $this->assertStatusCode($res, 403);
    }

    // ── perfil público ────────────────────────────────────────────────────

    public function test_buscar_por_username_retorna_404_quando_nao_encontrado(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUsername')->willReturn(null);
        $ctrl = $this->makeController($service);
        $res  = $ctrl->buscarPorUsername($this->makeRequest('GET', '/api/perfil/naoexiste'), 'naoexiste');
        $this->assertStatusCode($res, 404);
    }

    public function test_buscar_por_username_retorna_perfil_publico(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUsername')->willReturn($this->makeUsuario());
        $ctrl = $this->makeController($service);
        $res  = $ctrl->buscarPorUsername($this->makeRequest('GET', '/api/perfil/testuser'), 'testuser');
        $this->assertStatusCode($res, 200);
        $body = $res->getBody();
        $this->assertArrayHasKey('username', $body['usuario']);
        $this->assertArrayNotHasKey('email', $body['usuario']); // não expõe email no perfil público
    }

    public function test_exibir_perfil_html_retorna_404_quando_nao_encontrado(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUsername')->willReturn(null);
        $ctrl = $this->makeController($service);
        $res  = $ctrl->exibirPerfilHtml($this->makeRequest('GET', '/perfil/naoexiste'), 'naoexiste');
        $this->assertStatusCode($res, 404);
    }

    // ── verificação de e-mail ─────────────────────────────────────────────

    public function test_enviar_verificacao_email_retorna_404_quando_usuario_nao_existe(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUuid')->willReturn(null);
        $ctrl = $this->makeController($service);
        $res  = $ctrl->enviarVerificacaoEmail($this->makeRequest('POST', '/api/usuarios/uuid/enviar-verificacao-email'), 'uuid');
        $this->assertStatusCode($res, 404);
    }

    public function test_enviar_verificacao_email_retorna_sucesso(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUuid')->willReturn($this->makeUsuario());
        $service->expects($this->once())->method('salvarTokenVerificacaoEmail');
        $ctrl = $this->makeController($service);
        $res  = $ctrl->enviarVerificacaoEmail($this->makeRequest('POST', '/api/usuarios/uuid/enviar-verificacao-email'), 'uuid');
        $this->assertStatusCode($res, 200);
        $this->assertArrayHasKey('token', $res->getBody());
    }

    public function test_verificar_email_retorna_400_com_token_invalido(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorTokenVerificacaoEmail')->willReturn(null);
        $ctrl = $this->makeController($service);
        $res  = $ctrl->verificarEmail($this->makeRequest('POST', '/api/usuarios/verificar-email/tok'), 'tok');
        $this->assertStatusCode($res, 400);
    }

    public function test_verificar_email_retorna_sucesso(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorTokenVerificacaoEmail')->willReturn($this->makeUsuario());
        $service->expects($this->once())->method('marcarEmailComoVerificado');
        $ctrl = $this->makeController($service);
        $res  = $ctrl->verificarEmail($this->makeRequest('POST', '/api/usuarios/verificar-email/tok'), 'tok');
        $this->assertStatusCode($res, 200);
        $this->assertJsonStatus($res, 'success');
    }

    public function test_verificar_email_status_retorna_422_sem_uuid(): void
    {
        $ctrl = $this->makeController();
        $res  = $ctrl->verificarEmailStatus($this->makeRequest('GET', '/api/usuarios/verificar-email-status'));
        $this->assertStatusCode($res, 422);
    }

    public function test_verificar_email_status_retorna_404_usuario_nao_encontrado(): void
    {
        $service = $this->makeService();
        $service->method('buscarPorUuid')->willReturn(null);
        $ctrl = $this->makeController($service);
        $req  = $this->makeRequest('GET', '/api/usuarios/verificar-email-status', [], ['uuid' => 'uuid-x']);
        $res  = $ctrl->verificarEmailStatus($req);
        $this->assertStatusCode($res, 404);
    }
}
