# 📘 Documentação Oficial Vupi.us API

Bem-vindo à documentação oficial da **Vupi.us API**, uma plataforma modular, moderna e robusta desenvolvida em PHP. 

O Vupi.us foi projetado com uma filosofia clara: **Simplicidade por fora, Poder por dentro.**

Esta documentação irá guiá-lo desde a configuração inicial do ambiente até a criação de módulos complexos e integrados.

---

## 📋 Índice

1.  [Pré-requisitos e Instalação](#1-pré-requisitos-e-instalação)
2.  [Configuração do Ambiente](#2-configuração-do-ambiente)
3.  [Arquitetura do Sistema](#3-arquitetura-do-sistema)
4.  [Guia de Desenvolvimento de Módulos](#4-guia-de-desenvolvimento-de-módulos)
    *   [Criando seu Primeiro Módulo](#41-criando-seu-primeiro-módulo)
    *   [Definindo Rotas](#42-definindo-rotas)
    *   [Injeção de Dependência](#43-injeção-de-dependência)
5.  [Comunicação Entre Módulos (Inter-Module Communication)](#5-comunicação-entre-módulos)
    *   [Dependência Obrigatória (Hard)](#51-dependência-obrigatória-hard)
    *   [Dependência Opcional (Soft)](#52-dependência-opcional-soft)
6.  [Segurança e Boas Práticas](#6-segurança-e-boas-práticas)
7.  [Comandos Úteis](#7-comandos-úteis)

---

## 1. Pré-requisitos e Instalação

Antes de começar, certifique-se de ter em sua máquina:
*   **PHP 8.2+**
*   **Composer** (Gerenciador de dependências)
*   **Git**
*   Um banco de dados (**MySQL** ou **PostgreSQL**)
*   Extensões PHP do banco:
    * PostgreSQL: `pdo_pgsql` / `pgsql`
    * MySQL: `pdo_mysql` / `mysqli`

### Passo a Passo

1.  **Clone o repositório:**
    ```bash
    git clone https://github.com/vupi.us/api_vupi.us_php.git
    cd api_vupi.us_php
    ```

2.  **Instale as dependências do projeto:**
    ```bash
    composer install
    ```

3.  **Instale as extensões de Banco de Dados no PHP:**
    
    *   **Para PostgreSQL:**
        ```bash
        sudo apt-get install php-pgsql
        # No Windows: Habilite 'extension=pgsql' e 'extension=pdo_pgsql' no php.ini
        ```
    
    *   **Para MySQL:**
        ```bash
        sudo apt-get install php-mysql
        # No Windows: Habilite 'extension=mysqli' e 'extension=pdo_mysql' no php.ini
        ```

---

## 2. Configuração do Ambiente

O Vupi.us utiliza variáveis de ambiente para configuração segura. Nunca edite o código para mudar senhas ou chaves de API.

1.  **Copie o arquivo de exemplo:**
    ```bash
    cp EXEMPLO.env .env
    # No Windows: copy EXEMPLO.env .env
    ```

2.  **Edite o arquivo `.env` com suas configurações (variáveis reais do projeto):**
    ```ini
    # Configurações do Banco de Dados
    DB_CONEXAO=postgresql  # ou mysql
    DB_HOST=localhost
    DB_PORT=5432
    DB_NOME=vupi_db
    DB_USUARIO=admin
    DB_SENHA=sua_senha

    # Configurações da Aplicação
    APP_URL=http://localhost:3005
    APP_ENV=development  # Use 'production' em produção
    APP_DEBUG=true       # Use 'false' em produção para esconder erros detalhados

    # JWT
    JWT_SECRET=
    JWT_API_SECRET=
    ```

3.  **Criar banco, tabelas e iniciar a API (recomendado):**
    O projeto possui um comando único de setup com menu e modo automático.

    *   Menu interativo:
        ```bash
        php vupi setup
        ```

    *   Tudo automático (gera secrets JWT se vazios, cria DB via Docker, migra, seed e sobe servidor):
        ```bash
        php vupi setup --auto --db-mode=docker --server=php --jwt=if-empty
        ```

    Ajuda:
    ```bash
    php vupi setup --help
    ```

4.  **Inicie o servidor de desenvolvimento:**
    ```bash
    php -S localhost:3005 index.php
    ```
    Acesse: `http://localhost:3005`

5.  **Migrations/seed (runner db):**
    ```bash
    php db
    php db migrate
    php db seed
    php db rollback
    ```

6.  **Gerar token JWT de API (opcional):**
    O arquivo `gerar_jwt.php` gera um token de API (1h) usando `JWT_API_SECRET` do `.env`.
    ```bash
    php gerar_jwt.php
    ```

---

## 3. Arquitetura do Sistema

O Vupi.us segue uma arquitetura modular "Zero Config".

*   **Kernel (`src/Kernel/`):** O núcleo do sistema. Contém o Container de Injeção de Dependência, Roteador, Logger e Loader de Módulos. Você raramente precisará mexer aqui.
*   **Módulos (`src/Modules/`):** Onde sua aplicação vive. Cada pasta aqui é um módulo independente.
*   **Public (`public/`):** Arquivos estáticos (imagens, CSS, JS).

---

## 4. Guia de Desenvolvimento de Módulos

### 4.1. Criando seu Primeiro Módulo

No Vupi.us, **uma pasta é um módulo**. Para criar um módulo chamado `Financeiro`, basta criar a pasta:

`src/Modules/Financeiro`

Estrutura recomendada:
```text
src/Modules/Financeiro/
├── Controllers/       # Endpoints da API
├── Services/          # Regras de Negócio
├── Repositories/      # Acesso a Dados
├── Entities/          # Modelos de Domínio
└── Routes/
    └── web.php        # Definição de Rotas
```

### 4.2. Definindo Rotas

Crie o arquivo `src/Modules/Financeiro/Routes/web.php`. O sistema o carrega automaticamente.

```php
<?php
use Src\Modules\Financeiro\Controllers\FaturaController;
use Src\Kernel\Middlewares\AuthHybridMiddleware;

// Rota Pública
$router->get('/api/faturas', [FaturaController::class, 'listar']);

// Rota Protegida (Requer Login)
$router->post('/api/faturas', [FaturaController::class, 'criar'], [
    AuthHybridMiddleware::class
]);
```

### 4.3. Injeção de Dependência

O Vupi.us possui um Container poderoso. Você não precisa instanciar classes manualmente com `new`. Apenas declare o que precisa no construtor.

**Exemplo: Controller usando Service**

```php
namespace Src\Modules\Financeiro\Controllers;

use Src\Modules\Financeiro\Services\FaturaService;
use Src\Kernel\Http\Response\Response;

class FaturaController
{
    // O Container cria o FaturaService automaticamente para você
    public function __construct(
        private FaturaService $service
    ) {}

    public function listar(): Response
    {
        $faturas = $this->service->todas();
        return Response::json($faturas);
    }
}
```

---

## 5. Comunicação Entre Módulos

Módulos frequentemente precisam conversar. O Vupi.us facilita isso de forma elegante.

### 5.1. Dependência Obrigatória (Hard)

Use quando seu módulo **PRECISA** de outro para funcionar. Se o outro módulo não existir, o sistema deve parar (erro fatal).

**Cenário:** O módulo `Financeiro` precisa buscar dados do usuário no módulo `Usuario`.

```php
namespace Src\Modules\Financeiro\Services;

use Src\Modules\Usuario\Services\UsuarioService;

class FaturaService
{
    public function __construct(
        private UsuarioService $usuarioService // Obrigatório
    ) {}

    public function emitirParaUsuario(string $uuid)
    {
        // Se o módulo Usuario for deletado, isso dará erro no boot (Seguro).
        $usuario = $this->usuarioService->buscar($uuid);
        // ...
    }
}
```

### 5.2. Dependência Opcional (Soft) 🔥

Use quando seu módulo **PODE USAR** outro, mas funciona sem ele. Isso permite desacoplamento total.

**Cenário:** O módulo `Financeiro` envia e-mail se o módulo `Email` existir. Se não existir, ele apenas gera a fatura sem enviar e-mail.

**Como fazer:** Adicione `?` (nullable) e inicialize com `= null`.

```php
namespace Src\Modules\Financeiro\Services;

use Src\Kernel\Contracts\EmailSenderInterface;

class FaturaService
{
    public function __construct(
        // O Container tenta injetar. Se a classe não existir, injeta NULL.
        private ?EmailSenderInterface $emailService = null
    ) {}

    public function emitir()
    {
        // Lógica principal (sempre funciona)
        $this->salvarNoBanco();

        // Lógica opcional (só executa se o módulo existir)
        // O operador '?->' (nullsafe) faz a verificação automaticamente.
        $this->emailService?->sendCustom(
            'cliente@email.com',
            'Sua Fatura',
            '<p>Fatura gerada com sucesso!</p>'
        );
    }
}
```

---

## 6. Segurança e Boas Práticas

1.  **Validação de Dados:** Nunca confie no input do usuário. Valide no Controller ou Service.
2.  **SQL Injection:** Use sempre os métodos do Repository ou PDO com *prepared statements*. Nunca concatene strings em SQL.
3.  **Isolamento:** Um módulo nunca deve acessar a tabela de outro módulo diretamente via SQL. Use o Service do outro módulo para obter dados.
4.  **Ambiente:** Mantenha `APP_DEBUG=false` em produção para não vazar stack traces sensíveis.

---

## 7. Comandos Úteis

*   **Iniciar servidor local:**
    `php -S localhost:3005 index.php`
    
*   **Setup (menu / automático):**
    `php vupi setup`
    `php vupi setup --auto --db-mode=docker --server=php --jwt=if-empty`

*   **Banco (migrations/seed):**
    `php db migrate`
    `php db seed`
    `php db rollback`

*   **Token JWT de API (1h):**
    `php gerar_jwt.php`

*   **Rodar testes (se houver):**
    `php vendor/bin/phpunit`

*   **Limpar caches (em produção):**
    Apague o arquivo `storage/modules_cache.php` se fizer alterações na estrutura de módulos em produção.

---

**Vupi.us API** — Construído para escalar. 🚀
