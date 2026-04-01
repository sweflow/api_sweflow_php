<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Modules\Usuario\Entities\Usuario;
use Src\Modules\Usuario\Exceptions\InvalidEmailException;
use Src\Modules\Usuario\Exceptions\InvalidPasswordException;
use Src\Modules\Usuario\Exceptions\InvalidUsernameException;

class UsuarioEntityTest extends TestCase
{
    private function makeUsuario(array $overrides = []): Usuario
    {
        return Usuario::registrar(
            $overrides['nome']     ?? 'João Silva',
            $overrides['username'] ?? 'joao.silva',
            $overrides['email']    ?? 'joao@example.com',
            $overrides['senha']    ?? 'Senha@123',
            null, null, null,
            $overrides['nivel']    ?? 'usuario'
        );
    }

    public function test_registrar_cria_usuario_valido(): void
    {
        $u = $this->makeUsuario();
        $this->assertSame('João Silva', $u->getNomeCompleto());
        $this->assertSame('joao.silva', $u->getUsername());
        $this->assertSame('joao@example.com', $u->getEmail());
        $this->assertSame('usuario', $u->getNivelAcesso());
        $this->assertTrue($u->isAtivo());
        $this->assertNotNull($u->getUuid());
    }

    public function test_senha_e_hasheada(): void
    {
        $u = $this->makeUsuario();
        $this->assertNotSame('Senha@123', $u->getSenhaHash());
        $this->assertTrue($u->verificarSenha('Senha@123'));
        $this->assertFalse($u->verificarSenha('senhaerrada'));
    }

    public function test_username_normalizado_para_minusculo(): void
    {
        $u = $this->makeUsuario(['username' => 'JoaoSilva']);
        $this->assertSame('joaosilva', $u->getUsername());
    }

    public function test_email_invalido_lanca_excecao(): void
    {
        $this->expectException(InvalidEmailException::class);
        $this->makeUsuario(['email' => 'nao-e-email']);
    }

    public function test_email_vazio_lanca_excecao(): void
    {
        $this->expectException(InvalidEmailException::class);
        $this->makeUsuario(['email' => '']);
    }

    public function test_username_curto_lanca_excecao(): void
    {
        $this->expectException(InvalidUsernameException::class);
        $this->makeUsuario(['username' => 'ab']);
    }

    public function test_username_com_caractere_especial_no_inicio_lanca_excecao(): void
    {
        $this->expectException(InvalidUsernameException::class);
        $this->makeUsuario(['username' => '.joao']);
    }

    public function test_username_com_multiplos_especiais_lanca_excecao(): void
    {
        $this->expectException(InvalidUsernameException::class);
        $this->makeUsuario(['username' => 'jo.ao.silva']);
    }

    public function test_senha_curta_lanca_excecao(): void
    {
        $this->expectException(InvalidPasswordException::class);
        $this->makeUsuario(['senha' => 'Ab1@']);
    }

    public function test_senha_sem_maiuscula_lanca_excecao(): void
    {
        $this->expectException(InvalidPasswordException::class);
        $this->makeUsuario(['senha' => 'senha@123']);
    }

    public function test_senha_sem_numero_lanca_excecao(): void
    {
        $this->expectException(InvalidPasswordException::class);
        $this->makeUsuario(['senha' => 'Senha@abc']);
    }

    public function test_senha_sem_especial_lanca_excecao(): void
    {
        $this->expectException(InvalidPasswordException::class);
        $this->makeUsuario(['senha' => 'Senha1234']);
    }

    public function test_ativar_desativar(): void
    {
        $u = $this->makeUsuario();
        $u->desativar();
        $this->assertFalse($u->isAtivo());
        $u->ativar();
        $this->assertTrue($u->isAtivo());
    }

    public function test_promover_para_nivel_valido(): void
    {
        $u = $this->makeUsuario();
        $u->promoverPara('admin');
        $this->assertSame('admin', $u->getNivelAcesso());
    }

    public function test_promover_para_nivel_invalido_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $u = $this->makeUsuario();
        $u->promoverPara('superuser');
    }

    public function test_alterar_senha_valida(): void
    {
        $u = $this->makeUsuario();
        $u->alterarSenha('NovaSenh@1');
        $this->assertTrue($u->verificarSenha('NovaSenh@1'));
        $this->assertFalse($u->verificarSenha('Senha@123'));
    }

    public function test_gerar_token_recuperacao_senha(): void
    {
        $u = $this->makeUsuario();
        $u->gerarTokenRecuperacaoSenha('abc123');
        $this->assertSame('abc123', $u->getTokenRecuperacaoSenha());
    }

    public function test_gerar_token_verificacao_email(): void
    {
        $u = $this->makeUsuario();
        $u->gerarTokenVerificacaoEmail('tok456');
        $this->assertSame('tok456', $u->getTokenVerificacaoEmail());
    }

    public function test_nivel_invalido_no_registro_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeUsuario(['nivel' => 'hacker']);
    }
}
