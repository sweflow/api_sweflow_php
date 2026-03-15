<?php
namespace Src\CLI;

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Src\Kernel\Database\PdoFactory;
use Src\Kernel\Support\DB\Migrator;
use Src\Kernel\Support\DB\PluginMigrator;

class SetupCommand
{
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
            echo "Sweflow Setup\n";
            echo "==============================\n";
            echo "1) Preparar .env (copiar EXEMPLO.env -> .env)\n";
            echo "2) Criar banco automaticamente (Docker)\n";
            echo "3) Rodar migrations (módulos + plugins)\n";
            echo "4) Rodar seeders (módulos + plugins)\n";
            echo "5) Subir servidor PHP (php -S)\n";
            echo "6) Subir servidor com PM2\n";
            echo "7) Validar conexão com banco\n";
            echo "8) Executar tudo automaticamente (recomendado)\n";
            echo "9) Gerar JWT_SECRET e JWT_API_SECRET (se estiverem vazios)\n";
            echo "10) Gerar token JWT de API (JWT_API_SECRET)\n";
            echo "0) Sair\n";
            echo "==============================\n";
            $choice = $this->prompt("Escolha uma opção");

            switch ($choice) {
                case '1':
                    $this->ensureEnvFile();
                    $this->pause();
                    break;
                case '2':
                    $this->createDatabaseDockerInteractive();
                    $this->pause();
                    break;
                case '3':
                    $this->migrateAll();
                    $this->pause();
                    break;
                case '4':
                    $this->seedAll();
                    $this->pause();
                    break;
                case '5':
                    $this->startPhpServer();
                    return;
                case '6':
                    $this->startPm2();
                    return;
                case '7':
                    $this->checkDbConnection();
                    $this->pause();
                    break;
                case '8':
                    $this->runAuto([]);
                    $this->pause();
                    break;
                case '9':
                    $this->ensureJwtSecrets('if-empty');
                    $this->pause();
                    break;
                case '10':
                    $this->printApiJwtToken();
                    $this->pause();
                    break;
                case '0':
                    return;
                default:
                    echo "Opção inválida.\n";
                    $this->pause();
            }
        }
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

        $dbMode = strtolower((string)($flags['db-mode'] ?? 'docker'));
        if ($dbMode === 'docker') {
            $this->createDatabaseDockerFromEnv();
        }

        $this->migrateAll();
        $this->seedAll();

        $apiToken = strtolower((string)($flags['api-token'] ?? 'skip'));
        if ($apiToken === 'generate') {
            $this->printApiJwtToken();
        }

        $server = strtolower((string)($flags['server'] ?? 'php'));
        if ($server === 'pm2') {
            $this->startPm2();
            return;
        }

        $this->startPhpServer();
    }

    private function printHelp(): void
    {
        echo "Uso:\n";
        echo "  php sweflow setup               # menu interativo\n";
        echo "  php sweflow setup --auto        # executa pipeline automático\n";
        echo "\n";
        echo "Flags (modo --auto):\n";
        echo "  --db-mode=docker|skip           # padrão: docker\n";
        echo "  --server=php|pm2                # padrão: php\n";
        echo "  --jwt=if-empty|skip             # padrão: if-empty\n";
        echo "  --api-token=generate|skip       # padrão: skip\n";
        echo "\n";
        echo "Pré-requisitos do modo docker:\n";
        echo "  - docker instalado e rodando\n";
        echo "  - .env configurado com DB_CONEXAO/DB_HOST/DB_PORT/DB_NOME/DB_USUARIO/DB_SENHA\n";
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
        $db = (string)($_ENV['DB_NOME'] ?? $_ENV['DB_DATABASE'] ?? 'sweflow_db');
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

        $container = $driver === 'mysql' ? 'sweflow-mysql' : 'sweflow-postgres';
        if ($this->dockerContainerExists($container)) {
            echo "✔ Container já existe: {$container}\n";
            return;
        }

        if ($driver === 'mysql') {
            $cmd = "docker run -d --name {$container} " .
                "-e MYSQL_ROOT_PASSWORD=\"" . $this->shellEscape($pass) . "\" " .
                "-e MYSQL_DATABASE=\"" . $this->shellEscape($db) . "\" " .
                "-e MYSQL_USER=\"" . $this->shellEscape($user) . "\" " .
                "-e MYSQL_PASSWORD=\"" . $this->shellEscape($pass) . "\" " .
                "-p {$port}:3306 mysql:8";

            $masked = str_replace($pass, '******', $cmd);
            $this->runSystem($cmd, $masked);
            echo "Aguarde alguns segundos para o MySQL iniciar.\n";
            return;
        }

        $cmd = "docker run -d --name {$container} " .
            "-e POSTGRES_USER=\"" . $this->shellEscape($user) . "\" " .
            "-e POSTGRES_PASSWORD=\"" . $this->shellEscape($pass) . "\" " .
            "-e POSTGRES_DB=\"" . $this->shellEscape($db) . "\" " .
            "-p {$port}:5432 postgres:16";

        $masked = str_replace($pass, '******', $cmd);
        $this->runSystem($cmd, $masked);
        echo "Aguarde alguns segundos para o PostgreSQL iniciar.\n";
    }

    private function migrateAll(): void
    {
        $this->reloadEnv();
        $pdo = PdoFactory::fromEnv();
        $runner = new Migrator($pdo, dirname(__DIR__, 2));
        $pluginRunner = new PluginMigrator($pdo, dirname(__DIR__, 2));
        echo "Rodando migrations (módulos)...\n";
        $runner->migrate();
        echo "Rodando migrations (plugins)...\n";
        $pluginRunner->migrateAll();
        echo "✔ Migrations finalizadas\n";
    }

    private function seedAll(): void
    {
        $this->reloadEnv();
        $pdo = PdoFactory::fromEnv();
        $runner = new Migrator($pdo, dirname(__DIR__, 2));
        $pluginRunner = new PluginMigrator($pdo, dirname(__DIR__, 2));
        echo "Rodando seeders (módulos)...\n";
        $runner->seed();
        echo "Rodando seeders (plugins)...\n";
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
            $pdo = PdoFactory::fromEnv();
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
        $port = (string)($_ENV['APP_PORT'] ?? '3005');
        $cmd = PHP_BINARY . " -S 0.0.0.0:{$port} index.php";
        echo "Iniciando servidor em http://0.0.0.0:{$port}\n";
        passthru($cmd);
    }

    private function startPm2(): void
    {
        $this->reloadEnv();
        $port = (string)($_ENV['APP_PORT'] ?? '3005');

        if (!$this->commandExists('pm2')) {
            echo "✖ PM2 não encontrado. Instale: npm install -g pm2\n";
            return;
        }

        $cmd = "pm2 start php --name sweflow-api -- -S 0.0.0.0:{$port} index.php";
        $this->runSystem($cmd);
        $this->runSystem("pm2 save");
        echo "✔ PM2 iniciado. Logs: pm2 logs sweflow-api\n";
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
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            system('cls');
            return;
        }
        system('clear');
    }

    private function commandExists(string $name): bool
    {
        $cmd = stripos(PHP_OS_FAMILY, 'Windows') !== false ? "where {$name}" : "command -v {$name}";
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        return $code === 0 && !empty($out);
    }

    private function dockerContainerExists(string $containerName): bool
    {
        $out = [];
        $code = 0;
        @exec("docker ps -a --format \"{{.Names}}\"", $out, $code);
        if ($code !== 0) {
            return false;
        }
        return in_array($containerName, array_map('trim', $out), true);
    }

    private function runSystem(string $cmd, ?string $maskedForOutput = null): void
    {
        $toPrint = $maskedForOutput ?? $cmd;
        echo "\n$ {$toPrint}\n";
        $code = 0;
        passthru($cmd, $code);
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
        $contents = is_file($envPath) ? (string)file_get_contents($envPath) : '';
        $lines = $contents === '' ? [] : preg_split("/\r\n|\n|\r/", $contents);
        if (!is_array($lines)) {
            $lines = [];
        }

        $found = false;
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)\s*$/';
        foreach ($lines as $i => $line) {
            if (!is_string($line)) continue;
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

    private function shellEscape(string $value): string
    {
        return str_replace('"', '\"', $value);
    }
}
