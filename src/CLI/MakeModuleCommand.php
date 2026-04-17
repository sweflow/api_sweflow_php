<?php

namespace Src\CLI;

/**
 * Gera a estrutura completa de um novo módulo independente.
 *
 * Uso: php vupi make:module NomeModulo
 *
 * Estrutura gerada:
 *   src/Modules/NomeModulo/
 *     Controllers/NomeModuloController.php
 *     Services/NomeModuloService.php
 *     Services/NomeModuloServiceInterface.php
 *     Repositories/NomeModuloRepository.php
 *     Repositories/NomeModuloRepositoryInterface.php
 *     Entities/NomeModulo.php
 *     Routes/web.php
 *     Database/Migrations/001_create_nome_modulo.php
 *     Database/Seeders/001_seed_nome_modulo.php
 */
class MakeModuleCommand
{
    public function handle(string $name): void
    {
        $base = __DIR__ . "/../Modules/$name";
        $dirs = [
            'Controllers', 'Services', 'Repositories',
            'Entities', 'Routes', 'Database/Migrations', 'Database/Seeders',
        ];

        foreach ($dirs as $dir) {
            $path = "$base/$dir";
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }

        $this->createRoutes($name, $base);
        $this->createController($name, $base);
        $this->createServiceInterface($name, $base);
        $this->createService($name, $base);
        $this->createRepositoryInterface($name, $base);
        $this->createRepository($name, $base);
        $this->createMigration($name, $base);
        $this->createSeeder($name, $base);
        $this->createConnection($base);

        echo "\033[32m✔ Módulo $name criado em src/Modules/$name\033[0m\n";
        echo "\n  Próximos passos:\n";
        echo "  1. Edite src/Modules/$name/Routes/web.php para definir as rotas\n";
        echo "  2. Implemente src/Modules/$name/Database/Migrations/001_create_" . strtolower($name) . ".php\n";
        echo "  3. Execute: php vupi migrate\n\n";
    }

    private function createRoutes(string $name, string $base): void
    {
        $lower = strtolower($name);
        file_put_contents("$base/Routes/web.php", <<<PHP
<?php

use Src\Modules\\{$name}\Controllers\\{$name}Controller;
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface \$router */

\$protected      = [AuthHybridMiddleware::class];
\$adminProtected = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Exemplo de rotas — ajuste conforme necessário
\$router->get('/api/{$lower}',         [{$name}Controller::class, 'listar'],  \$protected);
\$router->post('/api/{$lower}',        [{$name}Controller::class, 'criar'],   \$protected);
\$router->get('/api/{$lower}/{id}',    [{$name}Controller::class, 'buscar'],  \$protected);
\$router->put('/api/{$lower}/{id}',    [{$name}Controller::class, 'atualizar'], \$protected);
\$router->delete('/api/{$lower}/{id}', [{$name}Controller::class, 'deletar'], \$adminProtected);
PHP);
    }

    private function createController(string $name, string $base): void
    {
        $lower = strtolower($name);
        file_put_contents("$base/Controllers/{$name}Controller.php", <<<PHP
<?php

namespace Src\Modules\\{$name}\Controllers;

use Src\Kernel\Http\Response\Response;
use Src\Modules\\{$name}\Services\\{$name}ServiceInterface;

class {$name}Controller
{
    public function __construct(
        private {$name}ServiceInterface \$service
    ) {}

    public function listar(\$request): Response
    {
        return Response::json(['status' => 'success', 'data' => []]);
    }

    public function criar(\$request): Response
    {
        \$data = \$request->body ?? [];
        return Response::json(['status' => 'success', 'message' => 'Criado com sucesso.'], 201);
    }

    public function buscar(\$request, string \$id): Response
    {
        return Response::json(['status' => 'success', 'data' => null]);
    }

    public function atualizar(\$request, string \$id): Response
    {
        return Response::json(['status' => 'success', 'message' => 'Atualizado com sucesso.']);
    }

    public function deletar(\$request, string \$id): Response
    {
        return Response::json(['status' => 'success', 'message' => 'Removido com sucesso.']);
    }
}
PHP);
    }

    private function createServiceInterface(string $name, string $base): void
    {
        file_put_contents("$base/Services/{$name}ServiceInterface.php", <<<PHP
<?php

namespace Src\Modules\\{$name}\Services;

interface {$name}ServiceInterface
{
    public function listar(): array;
    public function criar(array \$data): string;
    public function buscarPorId(string \$id): ?array;
    public function atualizar(string \$id, array \$data): void;
    public function deletar(string \$id): void;
}
PHP);
    }

    private function createService(string $name, string $base): void
    {
        file_put_contents("$base/Services/{$name}Service.php", <<<PHP
<?php

namespace Src\Modules\\{$name}\Services;

use Src\Modules\\{$name}\Repositories\\{$name}RepositoryInterface;

class {$name}Service implements {$name}ServiceInterface
{
    public function __construct(
        private {$name}RepositoryInterface \$repository
    ) {}

    public function listar(): array
    {
        return \$this->repository->findAll();
    }

    public function criar(array \$data): string
    {
        return \$this->repository->insert(\$data);
    }

    public function buscarPorId(string \$id): ?array
    {
        return \$this->repository->findById(\$id);
    }

    public function atualizar(string \$id, array \$data): void
    {
        \$this->repository->update(\$id, \$data);
    }

    public function deletar(string \$id): void
    {
        \$this->repository->delete(\$id);
    }
}
PHP);
    }

    private function createRepositoryInterface(string $name, string $base): void
    {
        file_put_contents("$base/Repositories/{$name}RepositoryInterface.php", <<<PHP
<?php

namespace Src\Modules\\{$name}\Repositories;

interface {$name}RepositoryInterface
{
    public function findAll(): array;
    public function findById(string \$id): ?array;
    public function insert(array \$data): string;
    public function update(string \$id, array \$data): void;
    public function delete(string \$id): void;
}
PHP);
    }

    private function createRepository(string $name, string $base): void
    {
        $table = strtolower($name) . 's';
        file_put_contents("$base/Repositories/{$name}Repository.php", <<<PHP
<?php

namespace Src\Modules\\{$name}\Repositories;

use PDO;

class {$name}Repository implements {$name}RepositoryInterface
{
    public function __construct(private PDO \$pdo) {}

    public function findAll(): array
    {
        \$stmt = \$this->pdo->prepare("SELECT * FROM {$table} ORDER BY criado_em DESC");
        \$stmt->execute();
        return \$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string \$id): ?array
    {
        \$stmt = \$this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
        \$stmt->execute([':id' => \$id]);
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        return \$row ?: null;
    }

    public function insert(array \$data): string
    {
        // Implemente conforme os campos da sua tabela
        throw new \RuntimeException('insert() não implementado em {$name}Repository');
    }

    public function update(string \$id, array \$data): void
    {
        // Implemente conforme os campos da sua tabela
        throw new \RuntimeException('update() não implementado em {$name}Repository');
    }

    public function delete(string \$id): void
    {
        \$stmt = \$this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        \$stmt->execute([':id' => \$id]);
    }
}
PHP);
    }

    private function createMigration(string $name, string $base): void
    {
        $table = strtolower($name) . 's';
        file_put_contents("$base/Database/Migrations/001_create_{$table}.php", <<<PHP
<?php
/**
 * Migration: Módulo {$name} — Tabela {$table}
 */
return [
    'up' => function (PDO \$pdo): void {
        \$driver = \$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (\$driver === 'pgsql') {
            \$pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id         UUID        NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
                    criado_em  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMPTZ
                )
            ");
        } else {
            \$pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id         CHAR(36)  NOT NULL PRIMARY KEY,
                    criado_em  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },
    'down' => function (PDO \$pdo): void {
        \$pdo->exec("DROP TABLE IF EXISTS {$table}");
    },
];
PHP);
    }

    private function createSeeder(string $name, string $base): void
    {
        $table = strtolower($name) . 's';
        file_put_contents("$base/Database/Seeders/001_seed_{$table}.php", <<<PHP
<?php
/**
 * Seeder: Módulo {$name}
 */
return function (PDO \$pdo): void {
    // Insira dados iniciais aqui
    echo "  ✔ Seeder {$name}: nenhum dado inicial definido.\n";
};
PHP);
    }

    private function createConnection(string $base): void
    {
        // Lê DEFAULT_MODULE_CONNECTION do .env — padrão 'core' se não definido
        $conn = trim((string) ($_ENV['DEFAULT_MODULE_CONNECTION'] ?? getenv('DEFAULT_MODULE_CONNECTION') ?: 'core'));
        if (!in_array($conn, ['core', 'modules', 'auto'], true)) {
            $conn = 'core';
        }

        file_put_contents("$base/Database/connection.php", <<<PHP
<?php
// Define qual banco de dados este módulo usa.
// 'core'    → usa DB_* do .env (banco principal)
// 'modules' → usa DB2_* do .env (banco secundário)
// 'auto'    → o Kernel decide baseado na origem do módulo
return '{$conn}';
PHP);
    }
}
