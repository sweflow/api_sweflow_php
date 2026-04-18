# Módulos

## O que é um Módulo

Um módulo é uma unidade autônoma de funcionalidade que se integra ao kernel da Vupi.us API sem modificá-lo. Cada módulo tem suas próprias rotas, controllers, services, repositórios, entidades e migrações — tudo encapsulado em uma pasta dentro de `src/Modules/`.

O kernel descobre os módulos automaticamente ao iniciar. Não é necessário registrar nada fora da pasta do módulo.

---

## Nomenclatura e Convenções

Antes de criar qualquer arquivo, é fundamental conhecer as convenções de nomes usadas pelo sistema. O `ModuleLoader` e o `SimpleModuleProvider` dependem dessas convenções para descobrir e carregar os módulos corretamente.

### Nome do Módulo

O nome do módulo deve ser **PascalCase**, apenas letras e números, máximo de 64 caracteres.

```
Correto:   Produto, BlogPost, GestaoFinanceira, Crm
Incorreto: produto, blog-post, gestao_financeira, CRM2024!
```

Nomes reservados pelo sistema que **não podem** ser usados:

```
Auth, Usuario, Kernel, System, Core, IdeModuleBuilder, Documentacao
```

### Estrutura de Pastas

Todas as pastas usam **PascalCase**:

| Pasta | Obrigatória | Descrição |
|---|---|---|
| `Controllers/` | Sim | Controllers HTTP do módulo |
| `Routes/` | Sim | Arquivo de definição de rotas |
| `Services/` | Não | Lógica de negócio |
| `Repositories/` | Não | Acesso ao banco de dados |
| `Entities/` | Não | Entidades de domínio |
| `Middlewares/` | Não | Middlewares próprios do módulo |
| `Exceptions/` | Não | Exceções de domínio |
| `Database/` | Não | Migrações, seeders e conexão |
| `Database/Migrations/` | Não | Arquivos de migração |
| `Database/Seeders/` | Não | Arquivos de seed |

### Arquivos de Rota

O arquivo de rotas deve estar dentro da pasta `Routes/` e pode ter um dos seguintes nomes — o sistema tenta cada um nessa ordem:

```
Routes/web.php      ← recomendado para rotas mistas (API + páginas)
Routes/api.php      ← alternativo para módulos somente API
Routes/Routes.php   ← alternativo
```

### Arquivos de Migração

Migrações ficam em `Database/Migrations/` e devem ser nomeadas com prefixo numérico ou de data para garantir a ordem de execução:

```
001_create_produtos.php
002_add_preco_promocional.php
2026_01_01_000001_create_produtos_table.php
```

### Arquivos de Seeder

Seeders ficam em `Database/Seeders/` com prefixo numérico:

```
001_produtos_iniciais.php
```

### Arquivo de Conexão

```
Database/connection.php
```

### Namespaces

O namespace de todo código dentro de um módulo segue o padrão:

```
Src\Modules\{NomeDoModulo}\{Camada}
```

Exemplos:

```php
namespace Src\Modules\Produto\Controllers;
namespace Src\Modules\Produto\Services;
namespace Src\Modules\Produto\Repositories;
namespace Src\Modules\Produto\Entities;
namespace Src\Modules\Produto\Middlewares;
namespace Src\Modules\Produto\Exceptions;
```

Namespaces proibidos — módulos não podem declarar:

```
Src\Kernel\*
Src\Modules\Auth\*
Src\Modules\Usuario\*
Src\Modules\IdeModuleBuilder\*
```

---

## Estrutura Completa de um Módulo

```
src/Modules/Produto/
│
├── Controllers/                     [OBRIGATÓRIO]
│   └── ProdutoController.php
│
├── Routes/                          [OBRIGATÓRIO]
│   └── web.php
│
├── Services/                        [opcional]
│   └── ProdutoService.php
│
├── Repositories/                    [opcional]
│   └── ProdutoRepository.php
│
├── Entities/                        [opcional]
│   └── Produto.php
│
├── Middlewares/                     [opcional]
│   └── VerificaEstoqueMiddleware.php
│
├── Exceptions/                      [opcional]
│   └── ProdutoNotFoundException.php
│
└── Database/                        [opcional]
    ├── connection.php
    ├── Migrations/
    │   └── 001_create_produtos.php
    └── Seeders/
        └── 001_produtos_iniciais.php
```

---

## Camadas Obrigatórias

### 1. Controller

O controller recebe o `Request`, chama o service (ou repositório diretamente em casos simples) e retorna uma `Response`. Não contém lógica de negócio.

**Nomenclatura:** `{NomeDoModulo}Controller.php`

**Namespace:** `Src\Modules\{NomeDoModulo}\Controllers`

```php
<?php
// src/Modules/Produto/Controllers/ProdutoController.php

namespace Src\Modules\Produto\Controllers;

use Src\Kernel\Auth;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Produto\Services\ProdutoService;

class ProdutoController
{
    public function __construct(private ProdutoService $service)
    {
    }

    public function listar(Request $request): Response
    {
        $produtos = $this->service->listarTodos();
        return Response::json(['data' => $produtos]);
    }

    public function buscar(Request $request, string $id): Response
    {
        $produto = $this->service->buscarPorId($id);
        if ($produto === null) {
            return Response::json(['error' => 'Produto não encontrado.'], 404);
        }
        return Response::json(['data' => $produto]);
    }

    public function criar(Request $request): Response
    {
        $body = $request->body();

        $nome  = trim($body['nome'] ?? '');
        $preco = $body['preco'] ?? null;

        if ($nome === '' || $preco === null) {
            return Response::json(['error' => 'Nome e preço são obrigatórios.'], 422);
        }

        $produto = $this->service->criar($nome, (float) $preco);
        return Response::json(['data' => $produto], 201);
    }

    public function deletar(Request $request, string $id): Response
    {
        $this->service->deletar($id);
        return Response::json(['message' => 'Produto removido.']);
    }
}
```

### 2. Rotas

O arquivo de rotas define todos os endpoints do módulo. A variável `$router` é injetada automaticamente pelo `ModuleLoader` — não precisa ser instanciada.

**Arquivo:** `Routes/web.php`

```php
<?php
// src/Modules/Produto/Routes/web.php

use Src\Kernel\Auth;
use Src\Modules\Produto\Controllers\ProdutoController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Rota pública
$router->get('/api/produtos', [ProdutoController::class, 'listar']);

// Rota pública com rate limit
$router->get('/api/produtos/{id}', [ProdutoController::class, 'buscar'], Auth::limit(60));

// Rota privada — qualquer usuário autenticado
$router->post('/api/produtos', [ProdutoController::class, 'criar'], Auth::user());

// Rota admin
$router->delete('/api/produtos/{id}', [ProdutoController::class, 'deletar'], Auth::admin());
```

> O comentário `/** @var \Src\Kernel\Contracts\RouterInterface $router */` é recomendado para que IDEs como PHPStorm e VS Code ofereçam autocomplete correto.

---

## Camadas Opcionais

### 3. Entity

A entidade representa um objeto de domínio com regras de negócio internas. Ela não conhece o banco de dados — apenas encapsula estado e comportamento.

**Nomenclatura:** `{NomeDoModulo}.php` ou nome do conceito de domínio

**Namespace:** `Src\Modules\{NomeDoModulo}\Entities`

```php
<?php
// src/Modules/Produto/Entities/Produto.php

namespace Src\Modules\Produto\Entities;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class Produto
{
    private function __construct(
        private readonly UuidInterface $id,
        private string $nome,
        private float  $preco,
        private bool   $ativo,
    ) {}

    // Factory: cria um novo produto (gera UUID, valida regras)
    public static function criar(string $nome, float $preco): self
    {
        if (trim($nome) === '') {
            throw new \InvalidArgumentException('Nome do produto não pode ser vazio.');
        }
        if ($preco < 0) {
            throw new \InvalidArgumentException('Preço não pode ser negativo.');
        }

        return new self(
            id:    Uuid::uuid4(),
            nome:  trim($nome),
            preco: $preco,
            ativo: true,
        );
    }

    // Factory: reconstitui a partir de dados do banco
    public static function reconstituir(
        UuidInterface $id,
        string $nome,
        float  $preco,
        bool   $ativo,
    ): self {
        return new self($id, $nome, $preco, $ativo);
    }

    // Getters
    public function getId(): UuidInterface { return $this->id; }
    public function getNome(): string      { return $this->nome; }
    public function getPreco(): float      { return $this->preco; }
    public function isAtivo(): bool        { return $this->ativo; }

    // Métodos de domínio
    public function renomear(string $novoNome): void
    {
        if (trim($novoNome) === '') {
            throw new \InvalidArgumentException('Nome não pode ser vazio.');
        }
        $this->nome = trim($novoNome);
    }

    public function reajustarPreco(float $novoPreco): void
    {
        if ($novoPreco < 0) {
            throw new \InvalidArgumentException('Preço não pode ser negativo.');
        }
        $this->preco = $novoPreco;
    }

    public function desativar(): void { $this->ativo = false; }
    public function ativar(): void    { $this->ativo = true; }

    public function toArray(): array
    {
        return [
            'id'    => $this->id->toString(),
            'nome'  => $this->nome,
            'preco' => $this->preco,
            'ativo' => $this->ativo,
        ];
    }
}
```

### 4. Repository

O repositório é responsável exclusivamente pelo acesso ao banco de dados. Recebe e retorna entidades — nunca arrays brutos para o service.

**Nomenclatura:** `{NomeDoModulo}Repository.php`

**Namespace:** `Src\Modules\{NomeDoModulo}\Repositories`

```php
<?php
// src/Modules/Produto/Repositories/ProdutoRepository.php

namespace Src\Modules\Produto\Repositories;

use PDO;
use Ramsey\Uuid\Uuid;
use Src\Modules\Produto\Entities\Produto;

class ProdutoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return Produto[] */
    public function listarAtivos(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM produtos WHERE ativo = :ativo ORDER BY nome ASC'
        );
        $stmt->execute([':ativo' => true]);
        return array_map(
            fn(array $row) => $this->mapear($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function buscarPorId(string $id): ?Produto
    {
        $stmt = $this->pdo->prepare('SELECT * FROM produtos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->mapear($row) : null;
    }

    public function salvar(Produto $produto): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO produtos (id, nome, preco, ativo)
                    VALUES (:id, :nome, :preco, :ativo)
                    ON CONFLICT (id) DO UPDATE SET
                        nome  = EXCLUDED.nome,
                        preco = EXCLUDED.preco,
                        ativo = EXCLUDED.ativo';
        } else {
            $sql = 'INSERT INTO produtos (id, nome, preco, ativo)
                    VALUES (:id, :nome, :preco, :ativo)
                    ON DUPLICATE KEY UPDATE
                        nome  = VALUES(nome),
                        preco = VALUES(preco),
                        ativo = VALUES(ativo)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'    => $produto->getId()->toString(),
            ':nome'  => $produto->getNome(),
            ':preco' => $produto->getPreco(),
            ':ativo' => $produto->isAtivo(),
        ]);
    }

    public function deletar(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM produtos WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function mapear(array $row): Produto
    {
        return Produto::reconstituir(
            id:    Uuid::fromString($row['id']),
            nome:  $row['nome'],
            preco: (float) $row['preco'],
            ativo: (bool)  $row['ativo'],
        );
    }
}
```

### 5. Service

O service contém a lógica de negócio. Recebe o repositório via construtor (injeção de dependência) e orquestra as operações entre entidades e repositórios.

**Nomenclatura:** `{NomeDoModulo}Service.php`

**Namespace:** `Src\Modules\{NomeDoModulo}\Services`

```php
<?php
// src/Modules/Produto/Services/ProdutoService.php

namespace Src\Modules\Produto\Services;

use Src\Modules\Produto\Entities\Produto;
use Src\Modules\Produto\Repositories\ProdutoRepository;

class ProdutoService
{
    public function __construct(private ProdutoRepository $repository)
    {
    }

    public function listarTodos(): array
    {
        return array_map(
            fn(Produto $p) => $p->toArray(),
            $this->repository->listarAtivos()
        );
    }

    public function buscarPorId(string $id): ?array
    {
        $produto = $this->repository->buscarPorId($id);
        return $produto?->toArray();
    }

    public function criar(string $nome, float $preco): array
    {
        $produto = Produto::criar($nome, $preco);
        $this->repository->salvar($produto);
        return $produto->toArray();
    }

    public function deletar(string $id): void
    {
        $produto = $this->repository->buscarPorId($id);
        if ($produto === null) {
            throw new \DomainException('Produto não encontrado.', 404);
        }
        $this->repository->deletar($id);
    }
}
```

### 6. Migration

As migrações criam e removem tabelas no banco de dados. Cada arquivo retorna um array com as chaves `up` e `down`.

**Nomenclatura:** `{numero}_{descricao}.php`

**Namespace:** nenhum — são arquivos PHP puros que retornam um array

```php
<?php
// src/Modules/Produto/Database/Migrations/001_create_produtos.php

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS produtos (
                    id        UUID          NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
                    nome      VARCHAR(255)  NOT NULL,
                    preco     NUMERIC(10,2) NOT NULL DEFAULT 0,
                    ativo     BOOLEAN       NOT NULL DEFAULT TRUE,
                    criado_em TIMESTAMPTZ   NOT NULL DEFAULT NOW()
                )
            ");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_produtos_ativo ON produtos (ativo)');
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS produtos (
                    id        CHAR(36)       NOT NULL PRIMARY KEY,
                    nome      VARCHAR(255)   NOT NULL,
                    preco     DECIMAL(10,2)  NOT NULL DEFAULT 0,
                    ativo     TINYINT(1)     NOT NULL DEFAULT 1,
                    criado_em DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ativo (ativo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS produtos');
    },
];
```

### 7. Seeder

Seeders populam o banco com dados iniciais. Cada arquivo retorna uma closure que recebe o `PDO`.

**Nomenclatura:** `{numero}_{descricao}.php`

```php
<?php
// src/Modules/Produto/Database/Seeders/001_produtos_iniciais.php

return function (PDO $pdo): void {
    $produtos = [
        ['nome' => 'Produto A', 'preco' => 29.90],
        ['nome' => 'Produto B', 'preco' => 49.90],
        ['nome' => 'Produto C', 'preco' => 99.90],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO produtos (id, nome, preco) VALUES (:id, :nome, :preco)'
    );

    foreach ($produtos as $p) {
        // Verifica se já existe para não duplicar
        $check = $pdo->prepare('SELECT 1 FROM produtos WHERE nome = :nome LIMIT 1');
        $check->execute([':nome' => $p['nome']]);
        if ($check->fetchColumn()) {
            echo "  ⊘ Já existe: {$p['nome']}\n";
            continue;
        }

        $stmt->execute([
            ':id'    => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            ':nome'  => $p['nome'],
            ':preco' => $p['preco'],
        ]);
        echo "  ✔ Inserido: {$p['nome']}\n";
    }
};
```

### 8. Arquivo de Conexão

Define qual banco de dados o módulo usa. Se omitido, o sistema usa `auto` (herda `DEFAULT_MODULE_CONNECTION` do `.env`).

```php
<?php
// src/Modules/Produto/Database/connection.php

return 'core'; // usa DB_* (banco principal)
// return 'modules'; // usa DB2_* (banco secundário)
// return 'auto';    // herda DEFAULT_MODULE_CONNECTION do .env
```

### 9. Middleware Próprio

Quando o módulo precisa de uma lógica de autenticação ou validação específica, crie um middleware próprio.

**Nomenclatura:** `{Descricao}Middleware.php`

**Namespace:** `Src\Modules\{NomeDoModulo}\Middlewares`

```php
<?php
// src/Modules/Produto/Middlewares/VerificaEstoqueMiddleware.php

namespace Src\Modules\Produto\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Produto\Repositories\ProdutoRepository;

class VerificaEstoqueMiddleware implements MiddlewareInterface
{
    public function __construct(private ProdutoRepository $repository)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $id = $request->param('id') ?? '';
        $produto = $this->repository->buscarPorId($id);

        if ($produto === null || !$produto->isAtivo()) {
            return Response::json(['error' => 'Produto indisponível.'], 422);
        }

        return $next($request->withAttribute('produto', $produto));
    }
}
```

### 10. Exceções de Domínio

Exceções específicas do módulo para representar erros de negócio com semântica clara.

**Namespace:** `Src\Modules\{NomeDoModulo}\Exceptions`

```php
<?php
// src/Modules/Produto/Exceptions/ProdutoNotFoundException.php

namespace Src\Modules\Produto\Exceptions;

class ProdutoNotFoundException extends \DomainException
{
    public function __construct(string $id)
    {
        parent::__construct("Produto '{$id}' não encontrado.", 404);
    }
}
```

---

## Injeção de Dependências

O container resolve as dependências automaticamente via Reflection. Isso significa que você **não precisa registrar nada** para que o controller receba o service, e o service receba o repository, e o repository receba o `PDO`.

O fluxo de resolução automática para o exemplo acima é:

```
Router instancia ProdutoController
    └── Container resolve ProdutoService
            └── Container resolve ProdutoRepository
                    └── Container resolve PDO  ← já registrado no index.php
```

Para que isso funcione, os tipos dos parâmetros do construtor devem ser declarados explicitamente:

```php
// Correto — o container consegue resolver
public function __construct(private ProdutoService $service) {}

// Incorreto — o container não sabe o que injetar
public function __construct(private $service) {}
```

---

## Passo a Passo: Módulo Completo

A seguir, a construção completa do módulo `Produto` do zero, em ordem.

### Passo 1 — Criar a estrutura de pastas

```
src/Modules/Produto/
src/Modules/Produto/Controllers/
src/Modules/Produto/Services/
src/Modules/Produto/Repositories/
src/Modules/Produto/Entities/
src/Modules/Produto/Database/
src/Modules/Produto/Database/Migrations/
src/Modules/Produto/Routes/
```

### Passo 2 — Migration

Crie `src/Modules/Produto/Database/Migrations/001_create_produtos.php` com o conteúdo da seção [Migration](#6-migration) acima.

### Passo 3 — Arquivo de conexão

Crie `src/Modules/Produto/Database/connection.php`:

```php
<?php
return 'core';
```

### Passo 4 — Entity

Crie `src/Modules/Produto/Entities/Produto.php` com o conteúdo da seção [Entity](#3-entity) acima.

### Passo 5 — Repository

Crie `src/Modules/Produto/Repositories/ProdutoRepository.php` com o conteúdo da seção [Repository](#4-repository) acima.

### Passo 6 — Service

Crie `src/Modules/Produto/Services/ProdutoService.php` com o conteúdo da seção [Service](#5-service) acima.

### Passo 7 — Controller

Crie `src/Modules/Produto/Controllers/ProdutoController.php` com o conteúdo da seção [Controller](#1-controller) acima.

### Passo 8 — Rotas

Crie `src/Modules/Produto/Routes/web.php` com o conteúdo da seção [Rotas](#2-rotas) acima.

### Passo 9 — Executar a migration

```bash
php vupi migrate
```

O sistema detecta automaticamente as migrações novas e as executa. Não é necessário registrar o módulo em nenhum lugar.

### Passo 10 — Testar

```bash
# Listar produtos (público)
curl http://localhost:3005/api/produtos

# Criar produto (requer autenticação)
curl -X POST http://localhost:3005/api/produtos \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"nome": "Produto Teste", "preco": 49.90}'
```

O módulo está funcionando. O kernel o descobriu automaticamente ao iniciar.

---

## Módulo Mínimo (sem banco de dados)

Para módulos que não precisam de banco de dados — como integrações com APIs externas, utilitários ou proxies — a estrutura mínima é apenas duas camadas:

```
src/Modules/Calculadora/
    ├── Controllers/
    │   └── CalculadoraController.php
    └── Routes/
        └── web.php
```

```php
<?php
// src/Modules/Calculadora/Controllers/CalculadoraController.php

namespace Src\Modules\Calculadora\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class CalculadoraController
{
    public function somar(Request $request): Response
    {
        $body = $request->body();
        $a = (float) ($body['a'] ?? 0);
        $b = (float) ($body['b'] ?? 0);
        return Response::json(['resultado' => $a + $b]);
    }
}
```

```php
<?php
// src/Modules/Calculadora/Routes/web.php

use Src\Modules\Calculadora\Controllers\CalculadoraController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$router->post('/api/calculadora/somar', [CalculadoraController::class, 'somar']);
```

Pronto. Dois arquivos, zero configuração extra.

---

## Gerenciamento via CLI

```bash
# Criar scaffold de um novo módulo
php vupi make:module Produto

# Executar migrations de todos os módulos
php vupi migrate

# Migrations + seeders
php vupi migrate --seed

# Inspecionar um módulo
php vupi plugin:inspect Produto

# Instalar módulo do marketplace
php vupi plugin:install NomeDoModulo

# Desinstalar módulo
# (via dashboard em /dashboard ou via API admin)
```

---

## Resumo das Convenções

| Item | Convenção | Exemplo |
|---|---|---|
| Nome do módulo | PascalCase | `GestaoFinanceira` |
| Pasta do módulo | PascalCase | `src/Modules/GestaoFinanceira/` |
| Subpastas | PascalCase | `Controllers/`, `Services/`, `Routes/` |
| Arquivo de rotas | snake_case | `web.php`, `api.php` |
| Arquivo de migration | `{numero}_{descricao}.php` | `001_create_produtos.php` |
| Arquivo de seeder | `{numero}_{descricao}.php` | `001_produtos_iniciais.php` |
| Classe Controller | `{Nome}Controller` | `ProdutoController` |
| Classe Service | `{Nome}Service` | `ProdutoService` |
| Classe Repository | `{Nome}Repository` | `ProdutoRepository` |
| Classe Entity | nome do conceito | `Produto`, `Pedido` |
| Classe Middleware | `{Descricao}Middleware` | `VerificaEstoqueMiddleware` |
| Namespace base | `Src\Modules\{Nome}\{Camada}` | `Src\Modules\Produto\Controllers` |
