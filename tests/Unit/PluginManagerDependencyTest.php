<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Nucleo\PluginManager;
use Src\Kernel\Support\DB\PluginMigrator;

/**
 * Testes para verificação automática de dependências de instalação no PluginManager.
 *
 * Cobre:
 *   - Instalação bloqueada quando dependência não está presente
 *   - Instalação permitida quando dependência está em src/Modules/
 *   - Instalação permitida quando dependência está no plugins_registry.json
 *   - Módulo sem plugin.json instala normalmente
 *   - Módulo com plugin.json sem campo requires instala normalmente
 *   - Mensagem de erro amigável com nome do módulo faltante
 *   - Múltiplas dependências em falta listadas corretamente
 */
class PluginManagerDependencyTest extends TestCase
{
    private string $storageDir = '';
    private string $modulesDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Diretórios temporários isolados por teste
        // storageDir = {base}/storage → projectRoot = {base} → src/Modules = {base}/src/Modules
        $base = sys_get_temp_dir() . '/vupi_dep_test_' . uniqid();
        $this->storageDir = $base . '/storage';
        $this->modulesDir = $base . '/src/Modules';

        mkdir($this->storageDir, 0750, true);
        mkdir($this->modulesDir, 0750, true);

        // Registry vazio
        file_put_contents(
            $this->storageDir . '/plugins_registry.json',
            json_encode([], JSON_PRETTY_PRINT)
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir(dirname($this->storageDir));
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Cria um PluginManager com PluginMigrator mockado (sem PDO real).
     */
    private function makeManager(): PluginManager
    {
        // createStub: não verifica expectations — correto para dependências que só precisam existir
        $migrator = $this->createStub(PluginMigrator::class);

        return new PluginManager($migrator, $this->storageDir);
    }

    /**
     * Cria a estrutura de um módulo com plugin.json no diretório temporário.
     */
    private function createModule(string $name, array $pluginJson = []): string
    {
        $path = $this->modulesDir . '/' . $name;
        mkdir($path, 0750, true);

        if (!empty($pluginJson)) {
            file_put_contents(
                $path . '/plugin.json',
                json_encode($pluginJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        return $path;
    }

    /**
     * Adiciona um módulo ao plugins_registry.json como instalado e habilitado.
     */
    private function registerInstalledPlugin(string $name): void
    {
        $file = $this->storageDir . '/plugins_registry.json';
        $data = json_decode((string) file_get_contents($file), true) ?? [];
        $data[$name] = ['enabled' => true, 'installed_at' => date('c')];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Remove diretório recursivamente.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── Testes ────────────────────────────────────────────────────────────

    public function test_instala_modulo_sem_plugin_json(): void
    {
        // Módulo sem plugin.json não tem dependências — deve instalar normalmente
        $this->createModule('Simples');
        $manager = $this->makeManager();

        // Não deve lançar exceção
        $manager->install('Simples');

        $state = $manager->read();
        $this->assertArrayHasKey('Simples', $state);
        $this->assertTrue($state['Simples']['enabled']);
    }

    public function test_instala_modulo_com_plugin_json_sem_requires(): void
    {
        // plugin.json sem campo requires — deve instalar normalmente
        $this->createModule('SemDep', [
            'name'        => 'sweflow/module-semdep',
            'description' => 'Módulo sem dependências',
            'version'     => '1.0.0',
        ]);
        $manager = $this->makeManager();

        $manager->install('SemDep');

        $state = $manager->read();
        $this->assertArrayHasKey('SemDep', $state);
    }

    public function test_instala_modulo_com_requires_vazio(): void
    {
        // requires.modules vazio — deve instalar normalmente
        $this->createModule('DepVazia', [
            'requires' => ['modules' => []],
        ]);
        $manager = $this->makeManager();

        $manager->install('DepVazia');

        $this->assertArrayHasKey('DepVazia', $manager->read());
    }

    public function test_bloqueia_instalacao_quando_dependencia_nao_existe(): void
    {
        // Módulo Fatura depende de Usuario, mas Usuario não está instalado
        $this->createModule('Fatura', [
            'name'     => 'sweflow/module-fatura',
            'requires' => ['modules' => ['Usuario']],
        ]);
        $manager = $this->makeManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Usuario/');

        $manager->install('Fatura');
    }

    public function test_mensagem_de_erro_menciona_modulo_faltante(): void
    {
        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Usuario']],
        ]);
        $manager = $this->makeManager();

        try {
            $manager->install('Fatura');
            $this->fail('Deveria ter lançado RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('Fatura', $msg);
            $this->assertStringContainsString('Usuario', $msg);
        }
    }

    public function test_mensagem_amigavel_com_instrucao_de_instalacao(): void
    {
        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Email']],
        ]);
        $manager = $this->makeManager();

        try {
            $manager->install('Fatura');
            $this->fail('Deveria ter lançado RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // Mensagem exata esperada pelo marketplace
            $this->assertStringContainsString(
                'Não é possível instalar o módulo \'Fatura\'',
                $msg
            );
            $this->assertStringContainsString(
                'é necessário ter instalado o módulo Email',
                $msg
            );
            $this->assertStringContainsString(
                'Por favor, faça a instalação do módulo Email antes de tentar instalar este módulo novamente',
                $msg
            );
        }
    }

    public function test_permite_instalacao_quando_dependencia_esta_em_modules(): void
    {
        // Cria o módulo Usuario em src/Modules/ (dependência satisfeita)
        $this->createModule('Usuario');

        // Cria o módulo Fatura que depende de Usuario
        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Usuario']],
        ]);

        $manager = $this->makeManager();

        // Não deve lançar exceção
        $manager->install('Fatura');

        $this->assertArrayHasKey('Fatura', $manager->read());
    }

    public function test_permite_instalacao_quando_dependencia_esta_no_registry(): void
    {
        // Email não está em src/Modules/, mas está no registry como instalado
        $this->registerInstalledPlugin('Email');

        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Email']],
        ]);

        $manager = $this->makeManager();

        // Não deve lançar exceção — Email está no registry
        $manager->install('Fatura');

        $this->assertArrayHasKey('Fatura', $manager->read());
    }

    public function test_bloqueia_quando_dependencia_no_registry_esta_desabilitada(): void
    {
        // Email está no registry mas com enabled=false (desinstalado)
        $file = $this->storageDir . '/plugins_registry.json';
        file_put_contents($file, json_encode([
            'Email' => ['enabled' => false, 'installed_at' => date('c')],
        ], JSON_PRETTY_PRINT));

        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Email']],
        ]);

        $manager = $this->makeManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Email/');

        $manager->install('Fatura');
    }

    public function test_multiplas_dependencias_faltando_listadas_na_mensagem(): void
    {
        $this->createModule('Complexo', [
            'requires' => ['modules' => ['Usuario', 'Email', 'Pagamento']],
        ]);
        $manager = $this->makeManager();

        try {
            $manager->install('Complexo');
            $this->fail('Deveria ter lançado RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('Usuario', $msg);
            $this->assertStringContainsString('Email', $msg);
            $this->assertStringContainsString('Pagamento', $msg);
        }
    }

    public function test_instala_quando_todas_as_dependencias_satisfeitas(): void
    {
        // Cria todas as dependências
        $this->createModule('Usuario');
        $this->createModule('Email');

        $this->createModule('Complexo', [
            'requires' => ['modules' => ['Usuario', 'Email']],
        ]);

        $manager = $this->makeManager();

        $manager->install('Complexo');

        $this->assertArrayHasKey('Complexo', $manager->read());
    }

    public function test_estado_nao_e_alterado_quando_instalacao_bloqueada(): void
    {
        // Garante atomicidade: se a instalação falha, o registry não é modificado
        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Usuario']],
        ]);
        $manager = $this->makeManager();

        try {
            $manager->install('Fatura');
        } catch (\RuntimeException) {
            // esperado
        }

        // Registry não deve conter Fatura
        $state = $manager->read();
        $this->assertArrayNotHasKey('Fatura', $state);
    }

    public function test_dependencia_com_variacao_de_capitalizacao_no_registry(): void
    {
        // Registry pode ter 'email' (minúsculo) mas o requires declara 'Email'
        $file = $this->storageDir . '/plugins_registry.json';
        file_put_contents($file, json_encode([
            'email' => ['enabled' => true, 'installed_at' => date('c')],
        ], JSON_PRETTY_PRINT));

        $this->createModule('Fatura', [
            'requires' => ['modules' => ['Email']],
        ]);

        $manager = $this->makeManager();

        // Deve encontrar 'email' no registry mesmo que requires declare 'Email'
        $manager->install('Fatura');

        $this->assertArrayHasKey('Fatura', $manager->read());
    }
}
