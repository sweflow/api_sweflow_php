<?php
namespace Src\CLI;

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\Migrator;
use Src\Kernel\Support\DB\PluginMigrator;

class SetupCommand
{
    use RunsKernelMigrations;
    public function handle(array $argv = []): void
    {
        if (file_exists(dirname(__DIR__, 2) . '/.env')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->load();
        }

        $flags = $this->parseFlags($argv);
        if (($flags['help'] ?? false) === true) {
            $this->printHelp();
            return;
        }

        if (($flags['auto'] ?? false) === true) {
            $this->runAuto($flags);
            return;
        }

        $this->runMenu();
    }

    private function runMenu(): void
    {
        while (true) {
            $this->clearScreen();
            $this->printMenuOptions();
            $choice = $this->prompt("Escolha uma opção");
            if (!$this->handleMenuChoice($choice)) {
                return;
            }
        }
    }

    private function printMenuOptions(): void
    {
        echo "Vupi.us Setup\n";
        echo "==============================\n";
        echo "1)  Preparar .env (copiar EXEMPLO.env -> .env)\n";
        echo "2)  Subir banco via docker-compose (recomendado)\n";
        echo "3)  Criar banco via docker run (container avulso)\n";
        echo "4)  Rodar migrations (todas as conexões)\n";
        echo "5)  Rodar seeders (todas as conexões)\n";
        echo "6)  Subir servidor PHP em background (nohup)\n";
        echo "7)  Subir servidor com PM2 (instala se necessário)\n";
        echo "8)  Validar conexão com banco\n";
        echo "9)  Executar tudo automaticamente (recomendado)\n";
        echo "10) Gerar JWT_SECRET e JWT_API_SECRET (se estiverem vazios)\n";
        echo "11) Gerar token JWT de API (JWT_API_SECRET)\n";
        echo "12) Parar servidor (php -S / PM2)\n";
        echo "13) Reiniciar servidor\n";
        echo "14) Instalar Caddy + subir HTTPS em produção\n";
        echo "15) Subir Caddy em desenvolvimento (HTTPS local via mkcert)\n";
        echo "16) Subir PM2 + Caddy em produção\n";
        echo "17) Reiniciar PM2 + Caddy\n";
        echo "18) Parar PM2 + Caddy\n";
        echo "19) Fazer backup do banco de dados\n";
        echo "20) Importar backup do banco de dados\n";
        echo "21) Status das migrations\n";
        echo "22) Rodar migrations apenas conexão core (DB)\n";
        echo "23) Rodar migrations apenas conexão modules (DB2)\n";
        echo "24) \033[1;32m[RECOMENDADO PRODUÇÃO]\033[0m PHP-FPM + Caddy (substitui php -S / PM2)\n";
        echo "0)  Sair\n";
        echo "==============================\n";
    }

    /** Returns false when the user chooses to exit. */
    private function handleMenuChoice(string $choice): bool
    {
        $actions = [
            '1'  => fn() => $this->ensureEnvFile(),
            '2'  => fn() => $this->startDatabaseDockerCompose(),
            '3'  => fn() => $this->createDatabaseDockerInteractive(),
            '4'  => fn() => $this->migrateAll(),
            '5'  => fn() => $this->seedAll(),
            '6'  => fn() => $this->startPhpServerBackground(),
            '7'  => fn() => $this->startPm2(),
            '8'  => fn() => $this->checkDbConnection(),
            '9'  => fn() => $this->runAuto([]),
            '10' => fn() => $this->ensureJwtSecrets('if-empty'),
            '11' => fn() => $this->printApiJwtToken(),
            '12' => fn() => $this->stopServer(),
            '13' => fn() => $this->restartServer(),
            '14' => fn() => $this->startCaddyProduction(),
            '15' => fn() => $this->startCaddyDev(),
            '16' => fn() => $this->startPm2WithCaddy(),
            '17' => fn() => $this->restartPm2WithCaddy(),
            '18' => fn() => $this->stopPm2WithCaddy(),
            '19' => fn() => $this->backupDatabase(),
            '20' => fn() => $this->restoreDatabase(),
            '21' => fn() => $this->migrateStatus(),
            '22' => fn() => $this->migrateCore(),
            '23' => fn() => $this->migrateModules(),
            '24' => fn() => $this->startFpmWithCaddy(),
        ];

        if ($choice === '0') {
            return false;
        }

        if (isset($actions[$choice])) {
            ($actions[$choice])();
        } else {
            echo "Opção inválida.\n";
        }

        $this->pause();
        return true;
    }

    private function runAuto(array $flags): void
    {
        $this->ensureEnvFile(false);
        $this->reloadEnv();

        $jwtMode = strtolower((string)($flags['jwt'] ?? 'if-empty'));
        if ($jwtMode !== 'skip') {
            $this->ensureJwtSecrets($jwtMode);
            $this->reloadEnv();
        }

        $dbMode = strtolower((string)($flags['db-mode'] ?? 'compose'));
        if ($dbMode === 'compose') {
            $this->startDatabaseDockerCompose();
        } elseif ($dbMode === 'docker') {
            $this->createDatabaseDockerFromEnv();
        }

        $this->migrateAll();
        $this->seedAll();

        $apiToken = strtolower((string)($flags['api-token'] ?? 'skip'));
        if ($apiToken === 'generate') {
            $this->printApiJwtToken();
        }

        // Inicia o servidor PHP em background primeiro
        $server = strtolower((string)($flags['server'] ?? 'background'));
        if ($server === 'fpm+caddy') {
            $this->startFpmWithCaddy();
            return;
        }
        if ($server === 'pm2+caddy') {
            $this->startPm2WithCaddy();
            return;
        }
        if ($server === 'pm2') {
            $this->startPm2();
        } elseif ($server === 'php') {
            $this->startPhpServer(); // foreground — bloqueia aqui
            return;
        } else {
            $this->startPhpServerBackground();
        }

        // Caddy (proxy HTTPS na frente do PHP)
        $caddy = strtolower((string)($flags['caddy'] ?? 'skip'));
        if ($caddy === 'production') {
            $this->startCaddyProduction();
        } elseif ($caddy === 'dev') {
            $this->startCaddyDev();
        }
    }

    private function startFpmWithCaddy(): void
    {
        $this->reloadEnv();
        $root      = dirname(__DIR__, 2);
        $phpVer    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        // Ubuntu/Debian podem usar php8.2-fpm ou php-fpm8.2 dependendo da versão
        $fpmBin = null;
        foreach (["php{$phpVer}-fpm", "php-fpm{$phpVer}", 'php-fpm'] as $candidate) {
            if ($this->commandExists($candidate)) {
                $fpmBin = $candidate;
                break;
            }
        }

        // Verifica também se o serviço systemd existe mesmo sem o binário no PATH
        if ($fpmBin === null) {
            $svcCheck = new Process(['sudo', 'systemctl', 'status', "php{$phpVer}-fpm"]);
            $svcCheck->run();
            if ($svcCheck->getExitCode() !== 4) { // exit 4 = unit not found
                $fpmBin = "php{$phpVer}-fpm"; // serviço existe, usa o nome padrão
            }
        }

        if ($fpmBin === null) {
            echo "✖ PHP-FPM não encontrado. Instale com:\n";
            echo "  sudo apt-get install php{$phpVer}-fpm php{$phpVer}-opcache\n";
            return;
        }

        // Nome do serviço systemd (sempre php{ver}-fpm independente do binário)
        $fpmService = "php{$phpVer}-fpm";
        $poolSrc   = $root . '/ci/php-fpm/vupi.us.conf';
        $poolDst   = "/etc/php/{$phpVer}/fpm/pool.d/vupi.us.conf";
        $caddySrc  = $root . '/Caddyfile.fpm';
        $caddyDst  = '/etc/caddy/Caddyfile';
        $domain    = preg_replace('#^https?://#', '', rtrim((string)($_ENV['APP_URL'] ?? 'api.vupi.us'), '/'));
        $appRoot   = $root;
        $email     = $_ENV['CADDY_EMAIL'] ?? ('admin@' . $domain);

        echo "\n\033[1;32m▶ PHP-FPM + Caddy — configuração de produção\033[0m\n\n";
        echo "  FPM binário: {$fpmBin}\n";
        echo "  FPM serviço: {$fpmService}\n\n";

        // ── 2. Instala pool config ────────────────────────────────────
        if (!is_file($poolSrc)) {
            echo "✖ Pool config não encontrado: {$poolSrc}\n";
            return;
        }

        // Substitui APP_ROOT no pool config antes de copiar
        $poolContent = (string) file_get_contents($poolSrc);
        $tmpPool = tempnam(sys_get_temp_dir(), 'vupi_pool_');
        file_put_contents($tmpPool, $poolContent);
        $this->runProcess(['sudo', 'cp', $tmpPool, $poolDst]);
        $this->runProcess(['sudo', 'chmod', '644', $poolDst]);
        unlink($tmpPool);
        echo "✔ Pool config instalado em {$poolDst}\n";

        // Garante diretório de logs
        $this->runProcess(['sudo', 'mkdir', '-p', '/var/log/php-fpm']);
        $this->runProcess(['sudo', 'chown', 'www-data:www-data', '/var/log/php-fpm']);

        // ── 3. Reinicia PHP-FPM ───────────────────────────────────────
        echo "▶ Reiniciando PHP-FPM...\n";
        $this->runProcess(['sudo', 'systemctl', 'restart', $fpmService]);
        $this->runProcess(['sudo', 'systemctl', 'enable',  $fpmService]);
        echo "✔ PHP-FPM {$phpVer} rodando com pool vupi.us\n";

        // ── 4. Instala Caddy se necessário ────────────────────────────
        if (!$this->commandExists('caddy')) {
            echo "Caddy não encontrado. Instalando...\n";
            $this->installCaddy();
        }

        // ── 5. Instala Caddyfile.fpm ──────────────────────────────────
        if (!is_file($caddySrc)) {
            echo "✖ Caddyfile.fpm não encontrado: {$caddySrc}\n";
            return;
        }
        $this->runProcess(['sudo', 'cp', $caddySrc, $caddyDst]);
        echo "✔ Caddyfile.fpm instalado em {$caddyDst}\n";

        // ── 6. Grava variáveis de ambiente para o Caddy ───────────────
        $envContent = "# Gerado pelo vupi.us setup em " . date('Y-m-d H:i:s') . "\n"
            . "APP_DOMAIN={$domain}\n"
            . "APP_ROOT={$appRoot}\n"
            . "CADDY_EMAIL={$email}\n"
            . "APP_ENV=" . ($_ENV['APP_ENV'] ?? 'production') . "\n";
        $tmpEnv = tempnam(sys_get_temp_dir(), 'vupi_env_');
        file_put_contents($tmpEnv, $envContent);
        $this->runProcess(['sudo', 'cp', $tmpEnv, '/etc/caddy/vupi.us.env']);
        $this->runProcess(['sudo', 'chmod', '640', '/etc/caddy/vupi.us.env']);
        unlink($tmpEnv);
        echo "✔ /etc/caddy/vupi.us.env atualizado\n";

        // ── 7. Inicia/recarrega Caddy ─────────────────────────────────
        $check = new Process(['sudo', 'systemctl', 'is-active', 'caddy']);
        $check->run();
        if (trim($check->getOutput()) === 'active') {
            $this->runProcess(['sudo', 'systemctl', 'daemon-reload']);
            $this->runProcess(['sudo', 'systemctl', 'reload', 'caddy']);
            echo "✔ Caddy recarregado\n";
        } else {
            $this->runProcess(['sudo', 'systemctl', 'enable', '--now', 'caddy']);
            echo "✔ Caddy iniciado\n";
        }

        $appUrl = (string)($_ENV['APP_URL'] ?? "https://{$domain}");
        echo "\n\033[1;32m✔ Produção ativa!\033[0m\n";
        echo "  API:         {$appUrl}\n";
        echo "  PHP-FPM:     systemctl status {$fpmService}\n";
        echo "  Caddy:       systemctl status caddy\n";
        echo "  Workers FPM: sudo systemctl reload {$fpmService}\n";
        echo "  Logs FPM:    tail -f /var/log/php-fpm/vupi.us-error.log\n";
        echo "  Logs Caddy:  journalctl -u caddy -f\n\n";
        echo "  \033[1;33mPara voltar ao php -S:\033[0m opção 16 (PM2 + Caddy)\n";
    }

    private function startPm2WithCaddy(): void
    {
        echo "▶ Iniciando PM2 + Caddy em produção...\n\n";

        if (!$this->commandExists('node') && !$this->commandExists('nodejs')) {
            echo "✖ Node.js não encontrado. Instale antes de continuar:\n";
            echo "  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -\n";
            echo "  sudo apt-get install -y nodejs\n";
            echo "\nAlternativa sem PM2: use a opção 14 (php -S + Caddy).\n";
            return;
        }

        $this->startPm2();
        $this->startCaddyProduction();
    }

    private function restartPm2WithCaddy(): void
    {
        echo "▶ Reiniciando PM2 + Caddy...\n\n";

        if ($this->commandExists('pm2')) {
            $check = new Process(['pm2', 'describe', 'vupi.us-api']);
            if ($check->run()) {
                echo "▶ Reiniciando PM2 vupi.us-api...\n";
                (new Process(['pm2', 'restart', 'vupi.us-api']))->passthru();
            } else {
                echo "⚠ PM2 vupi.us-api não está rodando. Iniciando...\n";
                $this->startPm2();
            }
        } else {
            echo "⚠ PM2 não encontrado. Pulando reinício do PM2.\n";
        }

        // Recarrega Caddy — usa startCaddyProduction que já atualiza vupi.us.env e variáveis
        $this->startCaddyProduction();
    }

    private function stopPm2WithCaddy(): void
    {
        echo "▶ Parando PM2 + Caddy...\n\n";

        // Para PM2
        if ($this->commandExists('pm2')) {
            $check = new Process(['pm2', 'describe', 'vupi.us-api']);
            if ($check->run()) {
                echo "▶ Parando PM2 vupi.us-api...\n";
                (new Process(['pm2', 'delete', 'vupi.us-api']))->passthru();
                (new Process(['pm2', 'save']))->passthru();
                echo "✔ PM2 vupi.us-api parado.\n";
            } else {
                echo "⚠ PM2 vupi.us-api não estava rodando.\n";
            }
        } else {
            echo "⚠ PM2 não encontrado.\n";
        }

        // Para Caddy
        if ($this->commandExists('caddy')) {
            echo "▶ Parando Caddy...\n";
            $exitCode = (new Process(['sudo', 'caddy', 'stop']))->passthru();
            if ($exitCode === 0) {
                echo "✔ Caddy parado.\n";
            } else {
                echo "⚠ Caddy pode já estar parado ou falhou ao parar.\n";
            }
        } else {
            echo "⚠ Caddy não encontrado.\n";
        }
    }

    // ── Caddy ─────────────────────────────────────────────────────────

    private function startCaddyProduction(): void
    {
        $this->reloadEnv();
        $root      = dirname(__DIR__, 2);
        $caddyfile = $root . '/Caddyfile';
        $port      = preg_replace('/[^0-9]/', '', (string)($_ENV['APP_PORT'] ?? '8000')) ?: '8000';
        $host      = $_ENV['APP_HOST'] ?? '127.0.0.1';
        $domain    = preg_replace('#^https?://#', '', rtrim((string)($_ENV['APP_URL'] ?? 'api.vupi.us'), '/'));
        $email     = $_ENV['CADDY_EMAIL'] ?? ('admin@' . $domain);

        if (!is_file($caddyfile)) {
            echo "✖ Caddyfile não encontrado em {$root}\n";
            return;
        }

        // Instala o Caddy se não estiver disponível
        if (!$this->commandExists('caddy')) {
            echo "Caddy não encontrado. Instalando...\n";
            $this->installCaddy();
            if (!$this->commandExists('caddy')) {
                echo "✖ Falha ao instalar o Caddy. Instale manualmente: https://caddyserver.com/docs/install\n";
                return;
            }
        }

        // Garante que o diretório de logs existe
        $this->runProcess(['sudo', 'mkdir', '-p', '/var/log/caddy']);

        // Copia o Caddyfile para /etc/caddy/
        echo "▶ Sincronizando Caddyfile com /etc/caddy/Caddyfile...\n";
        $this->runProcess(['sudo', 'cp', $caddyfile, '/etc/caddy/Caddyfile']);

        // Inicia o servidor PHP em background se não estiver rodando
        $pm2Running = $this->commandExists('pm2') && (new Process(['pm2', 'describe', 'vupi.us-api']))->run();
        $pidFile    = $root . '/storage/server.pid';
        if (!$pm2Running && !is_file($pidFile)) {
            echo "▶ Iniciando servidor PHP em background na porta {$port}...\n";
            $this->startPhpServerBackground();
        } else {
            echo "✔ Servidor já está rodando (PM2 ou php -S)\n";
        }

        // Monta as variáveis de ambiente para o Caddy ler {$APP_PORT}, {$APP_HOST}, etc.
        $envVars = [
            'APP_DOMAIN'  => $domain,
            'APP_PORT'    => $port,
            'APP_HOST'    => $host,
            'CADDY_EMAIL' => $email,
        ];

        // Se o systemd gerencia o Caddy, garante que o vupi.us.env está atualizado e recarrega
        $check = new Process(['sudo', 'systemctl', 'is-active', 'caddy']);
        $check->run();
        $systemdActive = trim($check->getOutput()) === 'active';

        // Gera /etc/caddy/vupi.us.env para o systemd EnvironmentFile
        $vupiEnvContent = "# Gerado pelo vupi.us setup em " . date('Y-m-d H:i:s') . "\n";
        foreach ($envVars as $k => $v) {
            $vupiEnvContent .= "{$k}={$v}\n";
        }
        $tmpEnv = tempnam(sys_get_temp_dir(), 'vupi_env_');
        file_put_contents($tmpEnv, $vupiEnvContent);
        $this->runProcess(['sudo', 'cp', $tmpEnv, '/etc/caddy/vupi.us.env']);
        $this->runProcess(['sudo', 'chmod', '640', '/etc/caddy/vupi.us.env']);
        unlink($tmpEnv);
        echo "✔ /etc/caddy/vupi.us.env atualizado (APP_PORT={$port}, APP_HOST={$host})\n";

        if ($systemdActive) {
            echo "▶ Recarregando Caddy via systemd...\n";
            $this->runProcess(['sudo', 'systemctl', 'daemon-reload']);
            $this->runProcess(['sudo', 'systemctl', 'reload', 'caddy']);
        } else {
            // Para instância anterior se existir
            (new Process(['sudo', 'caddy', 'stop']))->run();
            sleep(1);

            echo "▶ Iniciando Caddy em produção...\n";

            // Exporta as variáveis para o processo caddy start
            $envPrefix = '';
            foreach ($envVars as $k => $v) {
                $envPrefix .= "{$k}=" . escapeshellarg($v) . ' ';
            }

            $exitCode = (new Process([
                'sudo', 'env',
                "APP_DOMAIN={$domain}",
                "APP_PORT={$port}",
                "APP_HOST={$host}",
                "CADDY_EMAIL={$email}",
                'caddy', 'start', '--config', '/etc/caddy/Caddyfile',
            ]))->passthru();

            if ($exitCode !== 0) {
                echo "✖ Caddy falhou ao iniciar. Teste: sudo caddy validate --config /etc/caddy/Caddyfile\n";
                return;
            }
        }

        $appUrl = (string)($_ENV['APP_URL'] ?? "https://{$domain}");
        echo "✔ Caddy iniciado!\n";
        echo "  API disponível em: {$appUrl}\n";
        echo "  TLS gerenciado automaticamente pelo Let's Encrypt.\n";
        echo "  Para recarregar: sudo caddy reload --config /etc/caddy/Caddyfile\n";
        echo "  Para parar:      sudo caddy stop\n";
    }

    private function startCaddyDev(): void
    {
        $root = dirname(__DIR__, 2);
        $caddyfileDev = $root . '/Caddyfile.dev';

        if (!is_file($caddyfileDev)) {
            echo "✖ Caddyfile.dev não encontrado em {$root}\n";
            return;
        }

        if (!$this->commandExists('caddy')) {
            echo "Caddy não encontrado. Instalando...\n";
            $this->installCaddy();
            if (!$this->commandExists('caddy')) {
                echo "✖ Falha ao instalar o Caddy.\n";
                return;
            }
        }

        // Verifica/instala mkcert
        if (!$this->commandExists('mkcert')) {
            echo "mkcert não encontrado. Instalando...\n";
            $this->runProcess(['sudo', 'apt-get', 'install', '-y', 'mkcert', 'libnss3-tools']);
        }

        // Gera certificado local se não existir
        $certFile = $root . '/localhost+1.pem';
        if (!is_file($certFile)) {
            echo "▶ Gerando certificado local com mkcert...\n";
            $this->runProcess(['mkcert', '-install']);
            $this->runProcess(['mkcert', 'localhost', '127.0.0.1'], null);
            // mkcert gera os arquivos no diretório atual — move para a raiz do projeto
            foreach (['localhost+1.pem', 'localhost+1-key.pem'] as $f) {
                if (is_file($f) && !is_file($root . '/' . $f)) {
                    rename($f, $root . '/' . $f);
                }
            }
        } else {
            echo "✔ Certificado local já existe\n";
        }

        // Inicia o servidor PHP em background se não estiver rodando
        $port = preg_replace('/[^0-9]/', '', (string)($_ENV['APP_PORT'] ?? '3005')) ?: '3005';
        $pidFile = $root . '/storage/server.pid';
        if (!is_file($pidFile)) {
            echo "▶ Iniciando servidor PHP em background na porta {$port}...\n";
            $this->startPhpServerBackground();
        } else {
            echo "✔ Servidor PHP já está rodando (PID " . trim((string)file_get_contents($pidFile)) . ")\n";
        }

        // Inicia o Caddy em foreground (dev)
        echo "▶ Iniciando Caddy em desenvolvimento (HTTPS local)...\n";
        echo "  Acesse: https://localhost:2443\n";
        echo "  Pressione Ctrl+C para parar.\n\n";
        (new Process(['caddy', 'run', '--config', $caddyfileDev]))->passthru();
    }

    private function installCaddy(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            echo "No Windows, instale o Caddy manualmente: https://caddyserver.com/docs/install\n";
            return;
        }
        $this->runProcess(['sudo', 'apt-get', 'install', '-y', 'debian-keyring', 'debian-archive-keyring', 'apt-transport-https', 'curl']);

        $proc = new Process(['bash', '-c',
            'curl -1sLf "https://dl.cloudsmith.io/public/caddy/stable/gpg.key" | sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg && ' .
            'curl -1sLf "https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt" | sudo tee /etc/apt/sources.list.d/caddy-stable.list && ' .
            'sudo apt-get update && sudo apt-get install -y caddy'
        ]);
        $proc->passthru();
        echo "✔ Caddy instalado.\n";
    }

    private function printHelp(): void
    {
        echo "Uso:\n";
        echo "  php vupi setup               # menu interativo\n";
        echo "  php vupi setup --auto        # executa pipeline automático\n";
        echo "\n";
        echo "Flags (modo --auto):\n";
        echo "  --db-mode=compose|docker|skip   # padrão: compose\n";
        echo "  --server=background|php|pm2|pm2+caddy|fpm+caddy  # padrão: background\n";
        echo "  --jwt=if-empty|skip             # padrão: if-empty\n";
        echo "  --api-token=generate|skip       # padrão: skip\n";
        echo "  --caddy=production|dev|skip     # padrão: skip\n";
        echo "\n";
        echo "Exemplos:\n";
        echo "  # Produção REAL (PHP-FPM + Caddy — recomendado):\n";
        echo "  php vupi setup --auto --server=fpm+caddy\n";
        echo "\n";
        echo "  # Produção: PM2 + Caddy HTTPS automático:\n";
        echo "  php vupi setup --auto --server=pm2+caddy\n";
        echo "\n";
        echo "  # Produção: php -S + Caddy HTTPS automático:\n";
        echo "  php vupi setup --auto --caddy=production\n";
        echo "\n";
        echo "  # Desenvolvimento local com HTTPS via mkcert:\n";
        echo "  php vupi setup --auto --db-mode=skip --caddy=dev\n";
        echo "\n";
        echo "Pré-requisitos:\n";
        echo "  - docker + docker compose instalados e rodando\n";
        echo "  - .env configurado com DB_CONEXAO/DB_HOST/DB_PORT/DB_NOME/DB_USUARIO/DB_SENHA\n";
        echo "  - Para instalar dependências: sudo bash scripts/install-ubuntu.sh\n";
    }

    /**
     * Sobe o banco via docker-compose.yml (opção recomendada).
     * Detecta o driver no .env e sobe apenas o serviço correspondente.
     */
    private function startDatabaseDockerCompose(): void
    {
        $this->ensureEnvFile(false);
        $this->reloadEnv();

        $root = dirname(__DIR__, 2);
        $composePath = $root . '/docker-compose.yml';

        if (!is_file($composePath)) {
            echo "✖ docker-compose.yml não encontrado em {$root}\n";
            return;
        }

        if (!$this->commandExists('docker')) {
            echo "✖ Docker não encontrado. Execute: sudo bash scripts/install-ubuntu.sh\n";
            return;
        }

        $driver = strtolower((string)($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'postgresql'));
        $driver = $driver === 'pgsql' ? 'postgresql' : $driver;

        // Pergunta qual banco subir se não for automático
        $service = match ($driver) {
            'mysql'      => 'mysql',
            'postgresql' => 'postgres',
            default      => 'postgres',
        };

        echo "Subindo serviço '{$service}' via docker-compose...\n";

        $this->runProcess(['docker', 'compose', '-f', $composePath, 'up', '-d', $service]);

        // Aguarda healthcheck
        echo "Aguardando banco ficar pronto";
        $maxWait = 30;
        for ($i = 0; $i < $maxWait; $i++) {
            sleep(1);
            echo ".";
            $proc = new Process(['docker', 'compose', '-f', $composePath, 'ps', '--format', 'json']);
            $proc->run();
            if ($proc->isSuccessful() && str_contains($proc->getOutput(), '"healthy"')) {
                echo "\n✔ Banco pronto\n";
                return;
            }
        }
        echo "\n⚠ Banco pode ainda estar iniciando. Verifique com: docker compose ps\n";
    }

    private function ensureEnvFile(bool $echoInfo = true): void
    {
        $root = dirname(__DIR__, 2);
        $envPath = $root . '/.env';
        $examplePath = $root . '/EXEMPLO.env';

        if (is_file($envPath)) {
            if ($echoInfo) {
                echo "✔ .env já existe\n";
            }
            return;
        }

        if (!is_file($examplePath)) {
            echo "✖ EXEMPLO.env não encontrado\n";
            return;
        }

        $ok = copy($examplePath, $envPath);
        if ($ok) {
            echo "✔ .env criado a partir de EXEMPLO.env\n";
            echo "Edite o arquivo .env antes de rodar migrations em produção.\n";
        } else {
            echo "✖ Não foi possível criar .env\n";
        }
    }

    private function reloadEnv(): void
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            $dotenv = Dotenv::createImmutable($root);
            $dotenv->load();
        }
    }

    private function createDatabaseDockerInteractive(): void
    {
        $this->ensureEnvFile(false);
        $this->reloadEnv();

        $driver = strtolower((string)($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'postgresql'));
        $driver = $driver === 'pgsql' ? 'postgresql' : $driver;
        $input = $this->prompt("Banco no Docker (postgresql/mysql)", $driver);
        if ($input !== 'postgresql' && $input !== 'mysql') {
            echo "✖ Driver inválido. Use postgresql ou mysql.\n";
            return;
        }

        $this->createDatabaseDockerFromEnv($input);
    }

    private function createDatabaseDockerFromEnv(?string $overrideDriver = null): void
    {
        $driver = $overrideDriver ?: strtolower((string)($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'postgresql'));
        $driver = $driver === 'pgsql' ? 'postgresql' : $driver;

        $host = (string)($_ENV['DB_HOST'] ?? 'localhost');
        $port = (string)($_ENV['DB_PORT'] ?? ($driver === 'mysql' ? '3306' : '5432'));
        $db = (string)($_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? 'vupi_db');
        $user = (string)($_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? 'admin');
        $pass = (string)($_ENV['DB_SENHA'] ?? $_ENV['DB_PASSWORD'] ?? '');

        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            echo "✖ DB_HOST não é localhost. Modo docker pressupõe banco local.\n";
            return;
        }
        if ($pass === '') {
            echo "✖ DB_SENHA está vazio no .env\n";
            return;
        }

        if (!$this->commandExists('docker')) {
            echo "✖ Docker não encontrado. Instale o Docker ou use --db-mode=skip\n";
            return;
        }

        $container = $driver === 'mysql' ? 'vupi.us-mysql' : 'vupi.us-postgres';
        if ($this->dockerContainerExists($container)) {
            echo "✔ Container já existe: {$container}\n";
            return;
        }

        if ($driver === 'mysql') {
            $this->runProcess([
                'docker', 'run', '-d', '--name', $container,
                '-e', 'MYSQL_ROOT_PASSWORD=' . $pass,
                '-e', 'MYSQL_DATABASE=' . $db,
                '-e', 'MYSQL_USER=' . $user,
                '-e', 'MYSQL_PASSWORD=' . $pass,
                '-p', $port . ':3306', 'mysql:8',
            ], '***masked***');
            echo "Aguarde alguns segundos para o MySQL iniciar.\n";
            return;
        }

        $this->runProcess([
            'docker', 'run', '-d', '--name', $container,
            '-e', 'POSTGRES_USER=' . $user,
            '-e', 'POSTGRES_PASSWORD=' . $pass,
            '-e', 'POSTGRES_DB=' . $db,
            '-p', $port . ':5432', 'postgres:16',
        ], '***masked***');
        echo "Aguarde alguns segundos para o PostgreSQL iniciar.\n";
    }

    private function migrateAll(): void
    {
        $this->reloadEnv();
        $pdo  = PdoFactory::fromEnv('DB');
        $root = dirname(__DIR__, 2);

        $pdoModules = PdoFactory::hasSecondaryConnection()
            ? PdoFactory::fromEnv('DB2')
            : $pdo;

        $runner       = new Migrator($pdo, $root, $pdoModules);
        $pluginRunner = new PluginMigrator($pdoModules, $root);

        echo "Rodando migrations do kernel [core]...\n";
        $this->runKernelMigrations($pdo);

        echo "Rodando migrations dos módulos (cada um usa sua conexão definida)...\n";
        $runner->migrate();

        echo "Rodando migrations de plugins (vendor/vupi.us/)...\n";
        $pluginRunner->migratePluginsOnly();

        echo "✔ Migrations finalizadas\n";
    }

    private function seedAll(): void
    {
        $this->reloadEnv();
        $pdo  = PdoFactory::fromEnv('DB');
        $root = dirname(__DIR__, 2);

        $pdoModules = PdoFactory::hasSecondaryConnection()
            ? PdoFactory::fromEnv('DB2')
            : $pdo;

        $runner       = new Migrator($pdo, $root, $pdoModules);
        $pluginRunner = new PluginMigrator($pdoModules, $root);

        echo "Rodando seeders dos módulos (cada um usa sua conexão definida)...\n";
        $runner->seed();
        echo "Rodando seeders de plugins...\n";
        $pluginRunner->seedAll();
        echo "✔ Seeders finalizados\n";
    }

    private function ensureJwtSecrets(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if ($mode !== 'if-empty') {
            echo "✖ Modo JWT inválido. Use if-empty ou skip.\n";
            return;
        }

        $this->ensureEnvFile(false);
        $this->reloadEnv();

        $jwtSecret = (string)($_ENV['JWT_SECRET'] ?? '');
        $jwtApiSecret = (string)($_ENV['JWT_API_SECRET'] ?? '');

        $changes = [];
        if ($jwtSecret === '') {
            $changes['JWT_SECRET'] = $this->randomSecret();
        }
        if ($jwtApiSecret === '') {
            $changes['JWT_API_SECRET'] = $this->randomSecret();
        }

        if (!$changes) {
            echo "✔ JWT_SECRET e JWT_API_SECRET já estão preenchidos\n";
            return;
        }

        foreach ($changes as $k => $v) {
            $this->writeEnvValue($k, $v, true);
        }

        echo "✔ Secrets JWT gerados e gravados no .env:\n";
        foreach (array_keys($changes) as $k) {
            echo "  - {$k}\n";
        }
        echo "Se quiser gerar um token de API agora, use a opção 10 do menu.\n";
    }

    private function printApiJwtToken(): void
    {
        $this->ensureEnvFile(false);
        $this->reloadEnv();

        $secret = (string)($_ENV['JWT_API_SECRET'] ?? '');
        if ($secret === '') {
            echo "✖ JWT_API_SECRET não configurado. Gere pelo menu (opção 9) ou edite o .env.\n";
            return;
        }

        $payload = [
            'sub' => 'api_user_id',
            'exp' => time() + 3600,
            'api_access' => true,
            'tipo' => 'api',
        ];
        $jwt = JWT::encode($payload, $secret, 'HS256');
        echo "JWT (API):\n";
        echo $jwt . "\n";
    }

    private function checkDbConnection(): void
    {
        $this->reloadEnv();
        try {
            $pdo = PdoFactory::fromEnv('DB');
            $pdo->query('SELECT 1');
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            echo "✔ Conexão OK (driver: {$driver})\n";
        } catch (\Throwable $e) {
            echo "✖ Falha ao conectar no banco: " . $e->getMessage() . "\n";
        }
    }

    private function startPhpServer(): void
    {
        $this->reloadEnv();
        $port = preg_replace('/[^0-9]/', '', (string)($_ENV['APP_PORT'] ?? '3005')) ?: '3005';
        $host = $_ENV['APP_HOST'] ?? '127.0.0.1';
        echo "Iniciando servidor em http://{$host}:{$port} (foreground — Ctrl+C para parar)\n";
        (new Process([PHP_BINARY, '-S', "{$host}:{$port}", 'index.php']))->passthru();
    }

    private function startPhpServerBackground(): void
    {
        $this->reloadEnv();
        $root    = dirname(__DIR__, 2);
        $port    = preg_replace('/[^0-9]/', '', (string)($_ENV['APP_PORT'] ?? '3005')) ?: '3005';
        $host    = $_ENV['APP_HOST'] ?? '127.0.0.1';
        $logFile = $root . '/storage/server.log';
        $pidFile = $root . '/storage/server.pid';

        $this->stopPhpServer($pidFile);

        // Usa proc_open com array de argumentos — sem interpolação de shell
        $nullDevice  = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $proc = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", $root . '/index.php'],
            $descriptors,
            $pipes,
            $root
        );

        if (!is_resource($proc)) {
            echo "✖ Não foi possível iniciar o servidor em background.\n";
            return;
        }

        $status = proc_get_status($proc);
        $pid    = (string) $status['pid'];
        proc_close($proc);

        file_put_contents($pidFile, $pid);
        echo "✔ Servidor iniciado em background (PID {$pid}) em http://{$host}:{$port}\n";
        echo "  Logs: {$logFile}\n";
        echo "  Para parar: opção 12 do menu\n";
    }

    private function startPm2(): void
    {
        $this->reloadEnv();
        $root = dirname(__DIR__, 2);
        $port = preg_replace('/[^0-9]/', '', (string)($_ENV['APP_PORT'] ?? '3005')) ?: '3005';
        $host = $_ENV['APP_HOST'] ?? '127.0.0.1';

        // Instala PM2 automaticamente se não encontrado
        if (!$this->commandExists('pm2')) {
            echo "PM2 não encontrado. Tentando instalar via npm...\n";
            if (!$this->commandExists('npm')) {
                echo "✖ npm não encontrado. Instale Node.js primeiro:\n";
                echo "  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -\n";
                echo "  sudo apt-get install -y nodejs\n";
                return;
            }
            $proc = new Process(['npm', 'install', '-g', 'pm2']);
            $proc->passthru();
            /** @phpstan-ignore-next-line */
            if (!$this->commandExists('pm2')) {
                echo "✖ Falha ao instalar PM2. Tente manualmente: npm install -g pm2\n";
                return;
            }
            /** @phpstan-ignore-next-line */
            echo "✔ PM2 instalado com sucesso.\n";
        }

        // Para instância anterior se existir
        $check = new Process(['pm2', 'describe', 'vupi.us-api']);
        $check->run();
        if ($check->isSuccessful()) {
            echo "Parando instância anterior do PM2...\n";
            (new Process(['pm2', 'delete', 'vupi.us-api']))->passthru();
        }

        $this->runProcess([
            'pm2', 'start', PHP_BINARY,
            '--name', 'vupi.us-api',
            '--cwd', $root,
            '--',
            '-S', "{$host}:{$port}",
            $root . '/index.php',
        ]);
        $this->runProcess(['pm2', 'save']);
        echo "✔ PM2 iniciado em http://{$host}:{$port}\n";
        echo "  Logs:    pm2 logs vupi.us-api\n";
        echo "  Status:  pm2 status\n";
        echo "  Parar:   opção 12 do menu\n";
    }

    private function stopServer(): void
    {
        $root = dirname(__DIR__, 2);
        $pidFile = $root . '/storage/server.pid';
        $stopped = false;

        // Para PM2 se estiver rodando
        if ($this->commandExists('pm2')) {
            $check = new Process(['pm2', 'describe', 'vupi.us-api']);
            $check->run();
            if ($check->isSuccessful()) {
                $this->runProcess(['pm2', 'delete', 'vupi.us-api']);
                echo "✔ PM2 vupi.us-api parado.\n";
                $stopped = true;
            }
        }

        // Para php -S em background pelo PID
        $stopped = $this->stopPhpServer($pidFile) || $stopped;

        if (!$stopped) {
            echo "⚠ Nenhum servidor Vupi.us encontrado rodando.\n";
        }
    }

    private function stopPhpServer(string $pidFile): bool
    {
        if (!is_file($pidFile)) {
            return false;
        }
        $pid = trim((string) file_get_contents($pidFile));
        if (!is_numeric($pid)) {
            unlink($pidFile);
            return false;
        }
        // Verifica se o processo ainda existe
        $check = new Process(['kill', '-0', $pid]);
        $check->run();
        if (!$check->isSuccessful()) {
            unlink($pidFile);
            return false;
        }
        $kill = new Process(['kill', $pid]);
        $kill->run();
        unlink($pidFile);
        echo "✔ Servidor PHP (PID {$pid}) parado.\n";
        return true;
    }

    private function restartServer(): void
    {
        $root = dirname(__DIR__, 2);
        $port = preg_replace('/[^0-9]/', '', (string)($_ENV['APP_PORT'] ?? '3005')) ?: '3005';

        // Reinicia PM2 se estiver em uso
        if ($this->commandExists('pm2')) {
            $check = new Process(['pm2', 'describe', 'vupi.us-api']);
            $check->run();
            if ($check->isSuccessful()) {
                $this->runProcess(['pm2', 'restart', 'vupi.us-api']);
                echo "✔ PM2 vupi.us-api reiniciado.\n";
                return;
            }
        }

        // Reinicia php -S em background
        echo "Reiniciando servidor PHP em background...\n";
        $this->startPhpServerBackground();
    }

    private function parseFlags(array $argv): array
    {
        $flags = [];
        foreach ($argv as $arg) {
            if (!is_string($arg)) continue;
            if ($arg === '--help' || $arg === '-h') {
                $flags['help'] = true;
                continue;
            }
            if ($arg === '--auto') {
                $flags['auto'] = true;
                continue;
            }
            if ($arg === '--jwt') {
                $flags['jwt'] = 'if-empty';
                continue;
            }
            if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$k, $v] = explode('=', substr($arg, 2), 2);
                $flags[$k] = $v;
            }
        }
        return $flags;
    }

    private function prompt(string $label, ?string $default = null): string
    {
        $suffix = $default !== null && $default !== '' ? " [{$default}]" : '';
        echo "{$label}{$suffix}: ";
        $value = trim((string)fgets(STDIN));
        if ($value === '' && $default !== null) {
            return (string)$default;
        }
        return $value;
    }

    private function pause(): void
    {
        echo "\nPressione ENTER para continuar...";
        fgets(STDIN);
    }

    private function clearScreen(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            (new Process(['cmd', '/c', 'cls']))->passthru();
        } else {
            echo "\033[2J\033[H";
        }
    }

    private function commandExists(string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            return false;
        }

        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $proc = new Process(['where', $name]);
            $proc->run();
            return $proc->isSuccessful() && trim($proc->getOutput()) !== '';
        }

        // Tenta `which` primeiro (binário real, funciona sem shell)
        $proc = new Process(['which', $name]);
        $proc->run();
        if ($proc->isSuccessful() && trim($proc->getOutput()) !== '') {
            return true;
        }

        // Fallback: verifica diretamente nos paths mais comuns
        $paths = ['/usr/bin', '/usr/local/bin', '/bin', '/usr/sbin', '/usr/local/sbin', '/snap/bin'];
        foreach ($paths as $dir) {
            if (is_executable($dir . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    private function dockerContainerExists(string $containerName): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $containerName)) {
            return false;
        }
        $proc = new Process(['docker', 'ps', '-a', '--format', '{{.Names}}']);
        $proc->run();
        if (!$proc->isSuccessful()) return false;
        $names = array_map('trim', explode("\n", trim($proc->getOutput())));
        return in_array($containerName, $names, true);
    }

    /**
     * Executa um processo com array de argumentos (sem shell) e exibe output em tempo real.
     */
    private function runProcess(array $command, ?string $maskedLabel = null): void
    {
        $label = $maskedLabel ?? implode(' ', $command);
        echo "\n$ {$label}\n";
        $code = (new Process($command))->passthru();
        if ($code !== 0) {
            echo "✖ Comando falhou (exit code {$code})\n";
        }
    }
    private function randomSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function writeEnvValue(string $key, string $value, bool $onlyIfEmpty): void
    {
        $root = dirname(__DIR__, 2);
        $envPath = $root . '/.env';
        $contents = (is_file($envPath) && is_readable($envPath)) ? (string)file_get_contents($envPath) : '';
        $lines = $contents === '' ? [] : preg_split("/\r\n|\n|\r/", $contents);
        if (!is_array($lines)) {
            $lines = [];
        }

        $found = false;
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)\s*$/';
        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line, $m)) {
                $found = true;
                $current = trim((string)($m[1] ?? ''));
                $current = trim($current, " \t\n\r\0\x0B\"'");
                if ($onlyIfEmpty && $current !== '') {
                    return;
                }
                $lines[$i] = $key . '=' . $value;
                break;
            }
        }

        if (!$found) {
            $lines[] = $key . '=' . $value;
        }

        $new = implode("\n", $lines) . "\n";
        file_put_contents($envPath, $new);
    }

    private function backupDatabase(): void
    {
        $this->reloadEnv();
        $driver = strtolower((string)($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql'));
        $isPg   = $driver === 'postgresql' || $driver === 'pgsql';
        $host   = (string)($_ENV['DB_HOST']    ?? 'localhost');
        $port   = (string)($_ENV['DB_PORT']    ?? ($isPg ? '5432' : '3306'));
        $db     = (string)($_ENV['DB_NOME']    ?? $_ENV['DB_DATABASE'] ?? 'vupi_db');
        $user   = (string)($_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? 'admin');
        $pass   = (string)($_ENV['DB_SENHA']   ?? $_ENV['DB_PASSWORD'] ?? '');

        $root      = dirname(__DIR__, 2);
        $backupDir = $root . '/storage/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $timestamp = date('Y-m-d_His');
        $file      = "{$backupDir}/{$db}_{$timestamp}.sql";

        echo "▶ Fazendo backup do banco [{$driver}] {$db}...\n";

        // Detecta se o banco está rodando em Docker
        $container = $this->findDbContainer($isPg);

        if ($container !== null) {
            echo "  Usando docker exec no container [{$container}]...\n";
            $ok = $this->backupViaDocker($container, $isPg, $db, $user, $pass, $file);
        } else {
            // Fallback: ferramentas locais
            $ok = $this->backupViaLocal($isPg, $host, $port, $db, $user, $pass, $file);
        }

        if ($ok && is_file($file) && filesize($file) > 0) {
            $size = round(filesize($file) / 1024, 1);
            echo "✔ Backup salvo em: {$file} ({$size} KB)\n";
        } else {
            echo "✖ Falha ao gerar backup.\n";
            if (is_file($file)) unlink($file);
        }
    }

    private function restoreDatabase(): void
    {
        $this->reloadEnv();
        $driver = strtolower((string)($_ENV['DB_CONEXAO'] ?? $_ENV['DB_CONNECTION'] ?? 'mysql'));
        $isPg   = $driver === 'postgresql' || $driver === 'pgsql';
        $host   = (string)($_ENV['DB_HOST']    ?? 'localhost');
        $port   = (string)($_ENV['DB_PORT']    ?? ($isPg ? '5432' : '3306'));
        $db     = (string)($_ENV['DB_NOME']    ?? $_ENV['DB_DATABASE'] ?? 'vupi_db');
        $user   = (string)($_ENV['DB_USUARIO'] ?? $_ENV['DB_USERNAME'] ?? 'admin');
        $pass   = (string)($_ENV['DB_SENHA']   ?? $_ENV['DB_PASSWORD'] ?? '');

        $root      = dirname(__DIR__, 2);
        $backupDir = $root . '/storage/backups';

        $files = glob("{$backupDir}/*.sql") ?: [];
        if (empty($files)) {
            echo "✖ Nenhum backup encontrado em {$backupDir}\n";
            echo "  Faça um backup primeiro (opção 19).\n";
            return;
        }

        usort($files, fn($a, $b) => (int)filemtime($b) - (int)filemtime($a));

        echo "\nBackups disponíveis:\n";
        foreach ($files as $i => $f) {
            $size = round(filesize($f) / 1024, 1);
            $date = date('d/m/Y H:i:s', (int)filemtime($f));
            echo "  " . ($i + 1) . ") " . basename($f) . " ({$size} KB — {$date})\n";
        }

        $choice = (int)$this->prompt("\nEscolha o número do backup para restaurar (0 para cancelar)");
        if ($choice === 0 || !isset($files[$choice - 1])) {
            echo "Cancelado.\n";
            return;
        }

        $file = $files[$choice - 1];
        echo "\nℹ Modo seguro ativo: dados existentes serão preservados.\n";
        echo "  Apenas registros que ainda não existem no banco serão inseridos.\n";
        $confirm = $this->prompt("Confirmar restore? (s/N)");
        if (strtolower(trim($confirm)) !== 's') {
            echo "Cancelado.\n";
            return;
        }

        echo "▶ Restaurando backup em [{$driver}] {$db}...\n";
        echo "  Modo seguro: dados existentes serão preservados, apenas novos dados serão inseridos.\n";

        $container = $this->findDbContainer($isPg);

        if ($container !== null) {
            echo "  Usando docker exec no container [{$container}]...\n";
            $ok = $this->restoreViaDocker($container, $isPg, $db, $user, $pass, $file);
        } else {
            $ok = $this->restoreViaLocal($isPg, $host, $port, $db, $user, $pass, $file);
        }

        if ($ok) {
            echo "✔ Banco restaurado com sucesso a partir de: " . basename($file) . "\n";
        } else {
            echo "✖ Falha ao restaurar o backup.\n";
        }
    }

    /** Encontra o container Docker do banco (postgres ou mysql) em execução. */
    private function findDbContainer(bool $isPg): ?string
    {
        if (!$this->commandExists('docker')) {
            return null;
        }
        $image = $isPg ? 'postgres' : 'mysql';
        $proc  = new Process(['docker', 'ps', '--filter', "ancestor={$image}", '--format', '{{.Names}}']);
        $proc->run();
        $name = trim($proc->getOutput());
        if ($name !== '') {
            return explode("\n", $name)[0];
        }
        // Tenta pelo nome do container definido no docker-compose
        $proc2 = new Process(['docker', 'ps', '--format', '{{.Names}}']);
        $proc2->run();
        foreach (explode("\n", trim($proc2->getOutput())) as $line) {
            $line = trim($line);
            if ($isPg && str_contains(strtolower($line), 'postgres')) return $line;
            if (!$isPg && str_contains(strtolower($line), 'mysql'))    return $line;
        }
        return null;
    }

    private function backupViaDocker(string $container, bool $isPg, string $db, string $user, string $pass, string $file): bool
    {
        if ($isPg) {
            // --inserts + --on-conflict-do-nothing: gera INSERT ... ON CONFLICT DO NOTHING
            // --section=data: exporta apenas dados, sem CREATE TABLE/INDEX (evita erros "already exists" no restore)
            $cmd = ['docker', 'exec', '-e', "PGPASSWORD={$pass}", $container, 'pg_dump', '-U', $user, '-d', $db, '-F', 'p', '--inserts', '--on-conflict-do-nothing', '--section=data'];
        } else {
            // --insert: gera INSERT em vez de COPY; --skip-add-drop-table: não gera DROP TABLE
            $cmd = ['docker', 'exec', $container, 'mysqldump', "-u{$user}", "-p{$pass}", '--single-transaction', '--routines', '--triggers', '--insert-ignore', '--skip-add-drop-table', $db];
        }

        // Captura stdout e salva no arquivo
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $file, 'w'], 2 => STDERR];
        $proc = proc_open($cmd, $descriptors, $pipes); // NOSONAR — array de argumentos, sem injeção de shell
        if (!is_resource($proc)) return false;
        fclose($pipes[0]);
        return proc_close($proc) === 0;
    }

    private function restoreViaDocker(string $container, bool $isPg, string $db, string $user, string $pass, string $file): bool
    {
        if ($isPg) {
            $cmd = ['docker', 'exec', '-i', '-e', "PGPASSWORD={$pass}", $container, 'psql', '-U', $user, '-d', $db]; // NOSONAR — senha lida do .env em runtime, não hardcoded
        } else {
            $cmd = ['docker', 'exec', '-i', $container, 'mysql', "-u{$user}", "-p{$pass}", $db];
        }

        $descriptors = [0 => ['file', $file, 'r'], 1 => STDOUT, 2 => STDERR];
        $proc = proc_open($cmd, $descriptors, $pipes); // NOSONAR — array de argumentos, sem injeção de shell
        if (!is_resource($proc)) return false;
        return proc_close($proc) === 0;
    }

    private function backupViaLocal(bool $isPg, string $host, string $port, string $db, string $user, string $pass, string $file): bool
    {
        if ($isPg) {
            if (!$this->commandExists('pg_dump')) {
                echo "✖ pg_dump não encontrado. Instale: sudo apt install postgresql-client\n";
                return false;
            }
            $cmd         = ['pg_dump', '-h', $host, '-p', $port, '-U', $user, '-d', $db, '-F', 'p', '--inserts', '--on-conflict-do-nothing', '--section=data', '--no-password', '-f', $file];
            $descriptors = [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR];
            $env         = array_merge($_ENV, ['PGPASSWORD' => $pass]);
            $proc        = proc_open($cmd, $descriptors, $pipes, null, $env); // NOSONAR
            if (!is_resource($proc)) return false;
            fclose($pipes[0]);
            return proc_close($proc) === 0;
        }

        if (!$this->commandExists('mysqldump')) {
            echo "✖ mysqldump não encontrado. Instale: sudo apt install mysql-client\n";
            return false;
        }
        $cnf = tempnam(sys_get_temp_dir(), 'vupi_');
        file_put_contents($cnf, "[client]\npassword={$pass}\n");
        chmod($cnf, 0600);
        $cmd = ['mysqldump', "--defaults-extra-file={$cnf}", "-h{$host}", "-P{$port}", "-u{$user}", '--single-transaction', '--routines', '--triggers', '--insert-ignore', '--skip-add-drop-table', "--result-file={$file}", $db];
        $proc = new Process($cmd);
        $proc->passthru();
        $ok = $proc->isSuccessful();
        unlink($cnf);
        return $ok;
    }

    private function restoreViaLocal(bool $isPg, string $host, string $port, string $db, string $user, string $pass, string $file): bool
    {
        if ($isPg) {
            if (!$this->commandExists('psql')) {
                echo "✖ psql não encontrado. Instale: sudo apt install postgresql-client\n";
                return false;
            }
            $cmd         = ['psql', '-h', $host, '-p', $port, '-U', $user, '-d', $db, '--no-password', '-f', $file];
            $descriptors = [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR];
            $env         = array_merge($_ENV, ['PGPASSWORD' => $pass]);
            $proc        = proc_open($cmd, $descriptors, $pipes, null, $env); // NOSONAR
            if (!is_resource($proc)) return false;
            fclose($pipes[0]);
            return proc_close($proc) === 0;
        }

        if (!$this->commandExists('mysql')) {
            echo "✖ mysql não encontrado. Instale: sudo apt install mysql-client\n";
            return false;
        }
        $cnf = tempnam(sys_get_temp_dir(), 'vupi_');
        file_put_contents($cnf, "[client]\npassword={$pass}\n");
        chmod($cnf, 0600);
        $descriptors = [0 => ['file', $file, 'r'], 1 => STDOUT, 2 => STDERR];
        $cmd  = ['mysql', "--defaults-extra-file={$cnf}", "-h{$host}", "-P{$port}", "-u{$user}", $db];
        $proc = proc_open($cmd, $descriptors, $pipes); // NOSONAR
        if (!is_resource($proc)) { unlink($cnf); return false; }
        $ok = proc_close($proc) === 0;
        unlink($cnf);
        return $ok;
    }

    private function migrateStatus(): void
    {
        $this->reloadEnv();
        $pdo        = PdoFactory::fromEnv('DB');
        $root       = dirname(__DIR__, 2);
        $pdoModules = PdoFactory::hasSecondaryConnection() ? PdoFactory::fromEnv('DB2') : $pdo;
        $migrator   = new Migrator($pdo, $root, $pdoModules);
        echo "\n[migrate:status]\n";
        $output = $migrator->status();
        if ($output !== null) {
            echo $output;
        }
        echo "\n";
    }

    private function migrateCore(): void
    {
        $this->reloadEnv();
        $pdo        = PdoFactory::fromEnv('DB');
        $root       = dirname(__DIR__, 2);
        $pdoModules = PdoFactory::hasSecondaryConnection() ? PdoFactory::fromEnv('DB2') : $pdo;
        $migrator   = new Migrator($pdo, $root, $pdoModules);
        echo "\nRodando migrations do kernel [core]...\n";
        $this->runKernelMigrations($pdo);
        echo "\nRodando migrations dos módulos [core]...\n";
        $migrator->migrateCore();
        echo "✔ Migrations core finalizadas\n";
    }

    private function migrateModules(): void
    {
        $this->reloadEnv();
        $pdo          = PdoFactory::fromEnv('DB');
        $root         = dirname(__DIR__, 2);
        $pdoModules   = PdoFactory::hasSecondaryConnection() ? PdoFactory::fromEnv('DB2') : $pdo;
        $migrator     = new Migrator($pdo, $root, $pdoModules);
        $pluginRunner = new PluginMigrator($pdoModules, $root);
        echo "\nRodando migrations dos módulos [modules]...\n";
        $migrator->migrateModules();
        echo "\nRodando migrations de plugins (vendor/vupi.us/) [modules]...\n";
        $pluginRunner->migratePluginsOnly();
        echo "✔ Migrations modules finalizadas\n";
    }

}
