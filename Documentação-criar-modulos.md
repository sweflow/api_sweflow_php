# 🚀 Guia Completo: Desenvolvimento Modular no Sweflow API

Bem-vindo ao desenvolvimento no Sweflow. Este guia foi escrito para você, desenvolvedor, que quer criar funcionalidades poderosas sem perder tempo configurando arquivos complexos.

A filosofia aqui é simples: **Foque no seu código. O sistema cuida do resto.**

---

## 🎯 O Conceito "Zero Config"

No Sweflow, você não precisa registrar seus módulos em nenhum lugar. Não existe um arquivo `modules.json` gigante e centralizado.

A regra é simples: **Uma pasta é um módulo.**

Se você criar a pasta `src/Modules/Financeiro`, o sistema automaticamente entende que o módulo Financeiro existe e está pronto para uso.

---

## 🏗️ 1. Estrutura de um Módulo

Um módulo é apenas um agrupamento de classes que resolvem um problema específico (ex: Usuario, Financeiro, Notificacao).

A estrutura é flexível, mas recomendamos este padrão para manter a organização:

```text
src/Modules/Financeiro/
├── Controllers/       # Onde ficam seus endpoints da API
│   └── FaturaController.php
├── Services/          # Onde fica a lógica de negócio (o coração do módulo)
│   └── FaturaService.php
├── Repositories/      # (Opcional) Acesso ao banco de dados
│   └── FaturaRepository.php
├── Entities/          # (Opcional) Classes que representam seus dados
│   └── Fatura.php
├── Database/
│   ├── Migrations/    # (Opcional) Migrations do módulo
│   └── Seeders/       # (Opcional) Seeders do módulo
└── Routes/
    └── web.php        # ⚠️ Obrigatório se você quiser ter URLs acessíveis
```

---

## 🧰 1.1 Criar módulo e plugin via CLI

O projeto vem com uma CLI (`php sweflow`) que gera estrutura base automaticamente:

```bash
php sweflow make:module Financeiro
php sweflow make:plugin Email --capability=email-sender --description="Envio de e-mails via SMTP"
```

Ver comandos disponíveis:

```bash
php sweflow
```

---

## 🛣️ 2. Criando Rotas (Endpoints)

Para que seu módulo seja acessível via API (HTTP), você precisa definir rotas. O Sweflow procura automaticamente pelo arquivo `Routes/web.php` dentro do seu módulo.

**Exemplo Prático:**
Arquivo: `src/Modules/Financeiro/Routes/web.php`

```php
<?php

use Src\Modules\Financeiro\Controllers\FaturaController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;

// O framework já disponibiliza a variável $router para você.

// Rota Pública: Qualquer um pode acessar
$router->get('/faturas/publicas', [FaturaController::class, 'listarPublicas']);

// Rota Protegida: Exige login (Middleware de Autenticação)
$router->post('/faturas', [FaturaController::class, 'criar'], [
    AuthHybridMiddleware::class
]);
```

---

## 💉 3. Injeção de Dependência (A Mágica do Container)

Você nunca precisa usar `new Service()` manualmente. O **Container** do Sweflow é inteligente e cria as classes para você.

### Como funciona?
Basta declarar o que você precisa no **construtor** da sua classe.

**Exemplo:**
Seu `FaturaController` precisa do `FaturaService` para trabalhar.

```php
namespace Src\Modules\Financeiro\Controllers;

use Src\Modules\Financeiro\Services\FaturaService;
use Src\Kernel\Http\Response\Response;

class FaturaController
{
    // Apenas declare aqui. O Container vai criar o FaturaService e entregar pronto.
    public function __construct(
        private FaturaService $service
    ) {}

    public function criar($request)
    {
        $this->service->gerarFatura(...);
    }
}
```

---

## 🗄️ 3.1 Migrations e Seeders (criar tabela, alterar e popular dados)

O Sweflow executa migrations/seeders usando o runner `db` na raiz do projeto:

```bash
php db
php db migrate
php db seed
php db rollback
```

### Criar uma migration (nova tabela)

Crie um arquivo em:
`src/Modules/Financeiro/Database/Migrations/2026_03_15_000001_create_exemplos_table.php`

```php
<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS exemplos (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS exemplos");
    },
];
```

Aplicar no banco:

```bash
php db migrate
```

### Alterar uma tabela (nova migration)

Crie uma nova migration (não edite migrations antigas em produção):
`src/Modules/Financeiro/Database/Migrations/2026_03_15_000002_add_descricao_to_exemplos.php`

```php
<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE exemplos ADD COLUMN descricao TEXT");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE exemplos DROP COLUMN descricao");
    },
];
```

Aplicar:

```bash
php db migrate
```

### Adicionar dados (seeder)

Crie um seeder em:
`src/Modules/Financeiro/Database/Seeders/001_seed_exemplos.php`

```php
<?php

use PDO;

return function (PDO $pdo): void {
    $pdo->exec("
        INSERT INTO exemplos (nome, descricao)
        VALUES
          ('Primeiro registro', 'Seed inicial'),
          ('Segundo registro', 'Seed inicial')
    ");
};
```

Executar:

```bash
php db seed
```

---

## 🧩 3.2 Plugins: migrations versionadas e seeders

Plugins seguem a estrutura:

- `plugins/sweflow-module-seu-plugin/src/Database/Migrations/<versao>/*.php`
- `plugins/sweflow-module-seu-plugin/src/Database/Seeders/*.php`

Executar apenas migrations de plugins:

```bash
php sweflow plugin:migrate
php sweflow plugin:rollback NOME_DO_PLUGIN
```

## 🤝 4. Comunicação Entre Módulos (O Poder Real)

Aqui é onde o Sweflow brilha. Módulos muitas vezes precisam conversar. O Financeiro precisa do Usuário. O Pedido precisa do Estoque.

Aqui é onde o Sweflow brilha. Módulos muitas vezes precisam conversar. O Financeiro precisa do Usuário. O Pedido precisa do Estoque.

Temos duas formas de fazer isso, e você escolhe a melhor para cada caso.

### A. Dependência Obrigatória (Hard Dependency) 🔒
Use quando seu módulo **NÃO FUNCIONA** sem o outro.

*Exemplo: Não existe Fatura sem Usuário.*

```php
use Src\Modules\Usuario\Services\UsuarioService;

class FaturaService
{
    public function __construct(
        private UsuarioService $usuarioService // Obrigatório!
    ) {}

    public function criarFatura($userId)
    {
        // Se alguém deletar a pasta do módulo Usuario, o sistema vai parar aqui com um erro.
        // Isso é bom! Protege seu sistema de inconsistência.
        $user = $this->usuarioService->buscar($userId);
    }
}
```

### B. Dependência Opcional (Soft Dependency) 🍃
Use quando seu módulo **PODE USAR** o outro, mas funciona perfeitamente sem ele.

*Exemplo: O sistema envia e-mail de aviso, mas se o módulo de E-mail for removido, a fatura continua sendo gerada normalmente.*

**O Segredo:** Adicione `?` (nullable) e defina `= null`.

```php
use Src\Kernel\Contracts\EmailSenderInterface;

class FaturaService
{
    public function __construct(
        // O Container tenta encontrar o EmailService.
        // Se o módulo Email não existir (pasta deletada), ele injeta NULL suavemente.
        private ?EmailSenderInterface $emailService = null
    ) {}

    public function processar()
    {
        // ... lógica de gerar fatura ...

        // Uso elegante com nullsafe operator do PHP 8
        // Se $emailService for null, essa linha é ignorada. Sem erros. Sem if.
        $this->emailService?->enviarAviso("Fatura gerada!");
    }
}
```

---

## 🔎 Curiosidade: Como o Container Sabe?

Você não precisa decorar isso, mas é bom saber para se sentir seguro:

1.  **Detecção Automática:** O Container lê o tipo da variável (`UsuarioService`) usando *Reflection*.
2.  **Resolução Recursiva:** Se `UsuarioService` precisar de `UsuarioRepository`, e o Repository precisar de `PDO`, o Container resolve tudo em cascata.
3.  **Proteção contra Ciclos:** Se A precisa de B e B precisa de A, o Container detecta o loop infinito e avisa você com um erro claro, em vez de travar o servidor.
4.  **Tratamento de Erros:**
    *   Se você pedir uma classe que **não existe** e for opcional (`?`), ele injeta `null`.
    *   Se a classe **existe** mas tem erro no código (syntax error), ele estoura o erro real para você corrigir. Ele não esconde a sujeira.

---

## ✅ Resumo das Boas Práticas

1.  **Namespaces:** Sempre use `namespace Src\Modules\NomeDoModulo\Pasta;`.
2.  **Isolamento de Dados:** Um módulo nunca deve fazer SQL direto na tabela de outro módulo. Sempre peça o `Service` do outro módulo.
3.  **Simplicidade:** Comece pequeno. Crie apenas Controller e Service. Só crie Repositories se tiver queries SQL complexas.

Divirta-se codando! 🚀
