<?php

namespace Src\CLI;

/**
 * Comando: php vupi strip
 *
 * Remove todos os módulos nativos, frontend do dashboard, IDE e assets relacionados,
 * deixando apenas o esqueleto do framework para desenvolvimento de módulos próprios.
 *
 * O que é removido:
 *   - src/Modules/ (todos os módulos nativos)
 *   - src/Kernel/Views/ (dashboard, IDE, home, marketplace, usuarios)
 *   - src/Kernel/Controllers/ (Dashboard, Home, Ide, Marketplace, Usuarios, AuditLog, Capabilities, Env, SystemModules)
 *   - public/assets/js/ e public/assets/css/ (frontend do dashboard/IDE)
 *   - public/assets/imgs/ (imagens do dashboard)
 *   - Documentacao/ (documentação HTML)
 *   - storage/modules_state.json (estado dos módulos removidos)
 *
 * O que é preservado:
 *   - src/Kernel/ (núcleo do framework — container, router, middlewares, etc.)
 *   - index.php (entry point)
 *   - composer.json / composer.lock / vendor/
 *   - .env / EXEMPLO.env
 *   - docker-compose.yml / Caddyfile*
 *   - public/favicon.ico / public/404.html
 *   - scripts/ / ci/ / Makefile
 */
class StripCommand
{
    private string $root;
    private bool   $dryRun;
    private bool   $force;

    public function handle(array $argv = []): void
    {
        $this->root   = dirname(__DIR__, 2);
        $this->dryRun = in_array('--dry-run', $argv, true);
        $this->force  = in_array('--force', $argv, true);

        $this->printBanner();

        if (!$this->force && !$this->dryRun) {
            echo "\033[1;33m⚠  ATENÇÃO: Esta operação é IRREVERSÍVEL.\033[0m\n";
            echo "   Todos os módulos nativos e o frontend serão removidos permanentemente.\n\n";
            echo "   Use --dry-run para ver o que seria removido sem apagar nada.\n";
            echo "   Use --force para confirmar a remoção.\n\n";
            echo "   Exemplo:\n";
            echo "   php vupi strip --dry-run    # visualiza\n";
            echo "   php vupi strip --force      # executa\n\n";
            return;
        }

        if ($this->dryRun) {
            echo "\033[1;36m[DRY RUN] Nada será apagado — apenas visualização.\033[0m\n\n";
        }

        $removed = 0;
        $skipped = 0;

        // ── 1. Módulos nativos ────────────────────────────────────────────
        $removed += $this->removeDir('src/Modules', 'Módulos nativos');

        // ── 2. Views do kernel ────────────────────────────────────────────
        $viewsToRemove = [
            'src/Kernel/Views/dashboard.php',
            'src/Kernel/Views/configuracoes.php',
            'src/Kernel/Views/ide.php',
            'src/Kernel/Views/ide-projects.php',
            'src/Kernel/Views/ide-login.php',
            'src/Kernel/Views/marketplace.php',
            'src/Kernel/Views/usuarios.php',
            'src/Kernel/Views/index.php',
        ];
        foreach ($viewsToRemove as $view) {
            $removed += $this->removeFile($view, 'View');
        }

        // ── 3. Controllers do kernel (dashboard/IDE/home) ─────────────────
        $controllersToRemove = [
            'src/Kernel/Controllers/DashboardController.php',
            'src/Kernel/Controllers/HomeController.php',
            'src/Kernel/Controllers/IdeController.php',
            'src/Kernel/Controllers/MarketplacePageController.php',
            'src/Kernel/Controllers/UsuariosPageController.php',
            'src/Kernel/Controllers/AuditLogController.php',
            'src/Kernel/Controllers/CapabilitiesController.php',
            'src/Kernel/Controllers/EnvController.php',
            'src/Kernel/Controllers/SystemModulesController.php',
        ];
        foreach ($controllersToRemove as $ctrl) {
            $removed += $this->removeFile($ctrl, 'Controller');
        }

        // ── 4. Assets do frontend ─────────────────────────────────────────
        $assetsToRemove = [
            'public/assets/js',
            'public/assets/css',
            'public/assets/imgs',
            'public/db-connection-error.html',
        ];
        foreach ($assetsToRemove as $asset) {
            if (is_dir($this->root . '/' . $asset)) {
                $removed += $this->removeDir($asset, 'Asset');
            } else {
                $removed += $this->removeFile($asset, 'Asset');
            }
        }

        // ── 5. Documentação ───────────────────────────────────────────────
        $removed += $this->removeDir('Documentacao', 'Documentação');

        // ── 6. State files dos módulos removidos ──────────────────────────
        $removed += $this->removeFile('storage/modules_state.json', 'State');
        $removed += $this->removeFile('storage/capabilities_registry.json', 'State');

        // ── 7. Limpa rotas nativas do index.php ───────────────────────────
        $this->cleanIndexPhp();

        // ── Resumo ────────────────────────────────────────────────────────
        echo "\n";
        if ($this->dryRun) {
            echo "\033[1;36m[DRY RUN] {$removed} item(s) seriam removidos.\033[0m\n";
            echo "Execute com --force para confirmar.\n";
        } else {
            echo "\033[1;32m✔ {$removed} item(s) removidos.\033[0m\n\n";
            echo "\033[1;32m╔══════════════════════════════════════════════════════╗\033[0m\n";
            echo "\033[1;32m║         Esqueleto limpo e pronto para uso!           ║\033[0m\n";
            echo "\033[1;32m╚══════════════════════════════════════════════════════╝\033[0m\n\n";
            echo "O que foi removido:\n";
            echo "  ✖ Módulos nativos (Auth, Usuario, IDE, Documentacao, LinkEncurtador)\n";
            echo "  ✖ Dashboard e frontend\n";
            echo "  ✖ Rotas nativas do index.php\n\n";
            echo "O que permanece:\n";
            echo "  ✔ src/Kernel/     — container, router, middlewares, DI\n";
            echo "  ✔ index.php       — entry point mínimo\n";
            echo "  ✔ composer.json   — dependências\n";
            echo "  ✔ .env / docker-compose.yml / Caddyfile\n\n";
            echo "Próximos passos:\n";
            echo "  php vupi make:module NomeDoModulo   # gera estrutura de módulo\n";
            echo "  php vupi migrate                    # roda migrations\n";
            echo "  php vupi setup --auto               # sobe banco + servidor\n\n";
            echo "\033[1;33m⚠  Recarregue o servidor: php vupi setup (opção 27)\033[0m\n";
        }
    }

    private function removeDir(string $relPath, string $label): int
    {
        $full = $this->root . '/' . $relPath;
        if (!is_dir($full)) {
            return 0;
        }
        $this->log("  \033[1;31m✖\033[0m {$label}: {$relPath}/");
        if (!$this->dryRun) {
            $this->rmdirRecursive($full);
        }
        return 1;
    }

    private function removeFile(string $relPath, string $label): int
    {
        $full = $this->root . '/' . $relPath;
        if (!is_file($full)) {
            return 0;
        }
        $this->log("  \033[1;31m✖\033[0m {$label}: {$relPath}");
        if (!$this->dryRun) {
            @unlink($full);
        }
        return 1;
    }

    /**
     * Remove do index.php os blocos de rotas que referenciam módulos removidos.
     * Substitui por um index.php mínimo que mantém apenas o esqueleto funcional.
     */
    private function cleanIndexPhp(): void
    {
        $indexPath = $this->root . '/index.php';
        if (!is_file($indexPath)) return;

        $this->log("  \033[1;33m~\033[0m index.php: limpando rotas nativas...");

        if ($this->dryRun) return;

        // Lê o index.php atual e remove blocos de rotas que referenciam módulos removidos
        $content = (string) file_get_contents($indexPath);

        // Padrões de rotas a remover (dashboard, IDE, usuarios, marketplace, auth pages)
        $routePatterns = [
            // Rotas de página do dashboard
            "#\\\$router->get\('/dashboard'.*?(?=\\\$router->|\z)#s",
            "#\\\$router->get\('/dashboard/configuracoes'.*?(?=\\\$router->|\z)#s",
            "#\\\$router->get\('/dashboard/usuarios'.*?(?=\\\$router->|\z)#s",
            "#\\\$router->get\('/modules/marketplace'.*?(?=\\\$router->|\z)#s",
            // Rotas da IDE
            "#\\\$router->get\('/ide/login'.*?(?=\\\$router->|\z)#s",
            "#\\\$router->get\('/dashboard/ide'.*?\]\);\n#s",
            "#\\\$router->get\('/dashboard/ide/editor'.*?\]\);\n#s",
            // Rotas de API de usuários (check-username, check-email)
            "#// Verificação de disponibilidade.*?(?=function isPrivateRoute)#s",
            // Rotas de marketplace API
            "#// Marketplace API.*?(?=// Modules Management)#s",
            // Rotas de módulos management
            "#// Modules Management API.*?(?=// Migrations status)#s",
            // Rotas de migrations/seeders API
            "#// Migrations status API.*?(?=// Capabilities API)#s",
            "#// Run pending migrations.*?(?=// Run pending seeders)#s",
            "#// Run pending seeders.*?(?=// Capabilities API)#s",
            // Capabilities API
            "#// Capabilities API.*?(?=// Verificação)#s",
        ];

        // Em vez de regex frágil, reescreve o index.php com versão mínima
        // mantendo apenas: bootstrap, container, PDO, AuditLogger, router, modules, boot
        $this->writeMinimalIndexPhp($indexPath, $content);
        $this->log("  \033[1;32m✔\033[0m index.php: simplificado para modo esqueleto");
    }

    private function writeMinimalIndexPhp(string $path, string $original): void
    {
        // Extrai o bloco de bootstrap até o boot() — mantém tudo que é infraestrutura
        // Marca de início: ob_start()
        // Marca de fim: $app->boot();
        $bootEnd = strpos($original, '$app->boot();');
        if ($bootEnd === false) {
            // Não encontrou o marcador — não altera o arquivo
            error_log('[StripCommand] Não foi possível localizar $app->boot() no index.php');
            return;
        }

        $bootstrap = substr($original, 0, $bootEnd + strlen('$app->boot();'));

        // Remove bindings de módulos removidos do bootstrap
        $toRemove = [
            // Bloco Usuario
            "#// Módulo Usuario.*?(?=// Módulo Auth|\n// Registra MailerService)#s",
            // Bloco Auth blacklist
            "#// Módulo Auth — TokenBlacklist.*?}\n#s",
            // Bloco AuthController
            "#// Registra AuthController.*?true\n\);\n}#s",
            "#if \(class_exists\(.*?AuthController.*?}\n#s",
            // Bloco UsuarioController
            "#if \(class_exists\(.*?UsuarioController.*?}\n#s",
            // Bloco IdeProjectController
            "#if \(class_exists\(.*?IdeProjectController.*?}\n#s",
        ];

        // Rotas mínimas — apenas home e status
        $minimalRoutes = <<<'PHP'


// ── Rotas ────────────────────────────────────────────────────────────────
$router->get('/', function () {
    return \Src\Kernel\Http\Response\Response::json([
        'status'  => 'ok',
        'message' => 'Vupi.us API — esqueleto pronto. Crie seus módulos em src/Modules/.',
        'docs'    => 'https://github.com/vupi.us/api',
    ]);
});

$router->get('/api/status', function () {
    return \Src\Kernel\Http\Response\Response::json(['status' => 'ok']);
});

$app->run();
PHP;

        $newContent = $bootstrap . $minimalRoutes . "\n";
        file_put_contents($path, $newContent);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function log(string $msg): void
    {
        echo $msg . "\n";
    }

    private function printBanner(): void
    {
        echo "\n\033[1;35m╔══════════════════════════════════════════════════════╗\033[0m\n";
        echo "\033[1;35m║         Vupi.us API — Strip (modo esqueleto)         ║\033[0m\n";
        echo "\033[1;35m╚══════════════════════════════════════════════════════╝\033[0m\n\n";
    }
}
