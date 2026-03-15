# PMS — Plano de Execução para Migração de Servidor (Sweflow API)

Repositório alvo: `https://github.com/sweflow/api_sweflow_php.git`

Este PMS descreve um passo a passo completo para preparar um servidor Ubuntu, clonar o repositório, configurar variáveis de ambiente, subir banco (PostgreSQL ou MySQL), criar banco/tabelas, executar migrations/seeders e manter a API rodando em produção usando PM2.

---

## 1) Preparação do servidor (Ubuntu)

Atualize pacotes e instale dependências básicas:

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg git unzip
```

Recomendado: ajustar fuso horário do servidor (opcional):

```bash
sudo timedatectl set-timezone America/Bahia
timedatectl
```

---

## 2) Git atualizado e configuração de SSH (GitHub)

Instalar Git:

```bash
sudo apt install -y git
git --version
```

Gerar chave SSH para GitHub:

```bash
ssh-keygen -t ed25519 -C "seu-email-do-github"
```

Ativar o ssh-agent:

```bash
eval "$(ssh-agent -s)"
```

Adicionar a chave:

```bash
ssh-add ~/.ssh/id_ed25519
```

Copiar a chave pública:

```bash
cat ~/.ssh/id_ed25519.pub
```

Adicionar no GitHub:
1. Acesse o GitHub.
2. Settings.
3. SSH and GPG keys.
4. New SSH key.
5. Cole a chave pública.
6. Salve.

Testar conexão:

```bash
ssh -T git@github.com
```

---

## 3) PHP atualizado e extensões

Instalar PHP (versão mais recente disponível no Ubuntu):

```bash
sudo apt install -y php
php -v
```

Drivers do PostgreSQL e MySQL para PHP:

```bash
sudo apt install -y php-pgsql
sudo apt install -y php-mysql
```

Instalar XML para a versão do PHP instalada:

```bash
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo apt install -y "php${PHP_VERSION}-xml"
```

Esse pacote inclui:
- DOM
- XML
- SimpleXML
- XMLReader
- XMLWriter

Extensões recomendadas (comuns em produção):

```bash
sudo apt install -y "php${PHP_VERSION}-curl" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-zip"
```

---

## 4) Composer

Verificar se existe:

```bash
composer --version
```

Instalar/atualizar Composer:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer --version
```

---

## 5) PM2

Verifique se o PM2 está instalado e instale se necessário:

```bash
pm2 --version
npm install -g pm2
pm2 --version
```

Observação: PM2 depende do Node.js/NPM. Se `npm` não existir, instale:

```bash
sudo apt install -y nodejs npm
node -v
npm -v
```

---

## 6) Docker oficial (atualizado)

Execute tudo abaixo:

```bash
sudo apt update
sudo apt install ca-certificates curl gnupg -y

sudo install -m 0755 -d /etc/apt/keyrings

curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  noble stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y
```

Validar instalação:

```bash
docker --version
docker compose version
sudo docker run --rm hello-world
```

---

## 7) Clonar o repositório

Via HTTPS:

```bash
git clone https://github.com/sweflow/api_sweflow_php.git
cd api_sweflow_php
```

Ou via SSH:

```bash
git clone git@github.com:sweflow/api_sweflow_php.git
cd api_sweflow_php
```

Instalar dependências PHP:

```bash
composer install --no-dev
```

---

## 7.1) Comandos principais (help) — visão rápida

CLI do Sweflow (lista de comandos):

```bash
php sweflow
```

Setup automatizado com menu:

```bash
php sweflow setup --help
php sweflow setup
```

Runner de banco (migrations/seed):

```bash
php db
```

Gerar token JWT de API (para usar como Authorization em chamadas internas/automação):

```bash
php gerar_jwt.php
```

---

## 8) Configurar backend (.env)

Crie o arquivo `.env` a partir do `EXEMPLO.env`:

```bash
cp EXEMPLO.env .env
```

Ajuste variáveis essenciais (exemplos):
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_PORT=3005`
- `APP_URL=http://SEU_DOMINIO_OU_IP:3005`
- Banco:
  - `DB_CONEXAO=postgresql` (ou `mysql`)
  - `DB_HOST=localhost`
  - `DB_PORT=5432` (ou `3306`)
  - `DB_NOME=sweflow_db`
  - `DB_USUARIO=admin`
  - `DB_SENHA=...`
- JWT:
  - `JWT_SECRET=...`
  - `JWT_API_SECRET=...`

Gerar secrets (exemplo simples):

```bash
php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'
```

Gerar secrets automaticamente (recomendado):

```bash
php sweflow setup --auto --db-mode=skip --server=php --jwt=if-empty
```

Crie diretórios usados em runtime (se não existirem) e ajuste permissões:

```bash
mkdir -p storage cache
sudo chown -R "$USER":"$USER" storage cache
```

---

## 9) Banco de dados — criar automaticamente (Docker) + criar tabelas

Você pode usar Docker para criar o banco automaticamente (recomendado para padronizar a migração).

### Opção A — PostgreSQL (Docker)

Subir PostgreSQL e já criar a database:

```bash
docker run -d --name sweflow-postgres \
  -e POSTGRES_USER=admin \
  -e POSTGRES_PASSWORD='SENHA_FORTE_AQUI' \
  -e POSTGRES_DB=sweflow_db \
  -p 5432:5432 \
  postgres:16
```

Validar:

```bash
docker logs -n 50 sweflow-postgres
```

No `.env`, use:
- `DB_CONEXAO=postgresql`
- `DB_HOST=localhost`
- `DB_PORT=5432`
- `DB_NOME=sweflow_db`
- `DB_USUARIO=admin`
- `DB_SENHA=SENHA_FORTE_AQUI`

### Opção B — MySQL (Docker)

Subir MySQL e já criar a database:

```bash
docker run -d --name sweflow-mysql \
  -e MYSQL_ROOT_PASSWORD='SENHA_ROOT_FORTE' \
  -e MYSQL_DATABASE=sweflow_db \
  -e MYSQL_USER=admin \
  -e MYSQL_PASSWORD='SENHA_FORTE_AQUI' \
  -p 3306:3306 \
  mysql:8
```

No `.env`, use:
- `DB_CONEXAO=mysql`
- `DB_HOST=localhost`
- `DB_PORT=3306`
- `DB_NOME=sweflow_db`
- `DB_USUARIO=admin`
- `DB_SENHA=SENHA_FORTE_AQUI`

---

## 10) Criar banco (sem Docker) + criar tabelas

### PostgreSQL (host)

Instalar servidor (se necessário):

```bash
sudo apt install -y postgresql
sudo systemctl enable --now postgresql
```

Criar usuário e banco:

```bash
sudo -u postgres psql -c "CREATE USER admin WITH PASSWORD 'SENHA_FORTE_AQUI';"
sudo -u postgres psql -c "CREATE DATABASE sweflow_db OWNER admin;"
```

### MySQL (host)

Instalar servidor (se necessário):

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
```

Criar usuário e banco:

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS sweflow_db;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'admin'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';"
sudo mysql -e "GRANT ALL PRIVILEGES ON sweflow_db.* TO 'admin'@'localhost'; FLUSH PRIVILEGES;"
```

---

## 11) Criar tabelas do Sweflow API (migrations e/ou import SQL)

O projeto possui um runner de banco chamado `db` (na raiz do repositório) que executa:
- migrations de módulos (`src/Modules/*/Database/Migrations/*.php`)
- migrations de plugins (`plugins/*/src/Database/Migrations/...`)
- seeders (módulos e plugins)

### 11.1) Rodar migrations automaticamente

Com o `.env` configurado e o banco acessível:

```bash
php db migrate
```

Rodar seeders (para popular tabelas, quando existirem seeders):

```bash
php db seed
```

Rollback da última migration de módulos:

```bash
php db rollback
```

Rollback de plugin (última migration aplicada em plugins):

```bash
php sweflow plugin:rollback NOME_DO_PLUGIN
```

### 11.1.1) Help dos comandos (explicação objetiva)

**`php sweflow setup`**
- Abre um menu interativo para: preparar `.env`, criar DB via Docker, rodar migrations/seed, validar DB, iniciar servidor e gerar JWT.

**`php sweflow setup --help`**
- Mostra flags do modo automático.
- Flags principais:
  - `--auto`: roda pipeline automático (env → db (opcional) → migrate → seed → server).
  - `--db-mode=docker|skip`: cria banco automaticamente via Docker, ou pula essa etapa.
  - `--server=php|pm2`: inicia servidor com `php -S` ou via `pm2`.
  - `--jwt=if-empty|skip`: gera `JWT_SECRET` e `JWT_API_SECRET` se estiverem vazios.
  - `--api-token=generate|skip`: imprime um token JWT de API (1h) no final do setup.

**`php db`**
- Mostra os comandos disponíveis do runner de banco:
  - `php db migrate`: roda migrations de módulos e plugins.
  - `php db seed`: roda seeders de módulos e plugins.
  - `php db rollback`: reverte a última migration de módulos.

**`php sweflow plugin:migrate`**
- Roda apenas migrations de plugins instalados.

**`php sweflow plugin:rollback [plugin]`**
- Reverte a última migration aplicada em plugins (opcionalmente filtrando por nome do plugin).

---

## 11.3) Gerar token JWT de API (gerar_jwt.php)

O arquivo `gerar_jwt.php` gera um token JWT de API (válido por 1 hora) usando `JWT_API_SECRET` do `.env`.

1) Garanta que `JWT_API_SECRET` esteja preenchido (pode gerar automaticamente):

```bash
php sweflow setup --auto --db-mode=skip --server=php --jwt=if-empty
```

2) Gerar o token:

```bash
php gerar_jwt.php
```

3) Usar o token em requests (exemplo):

```bash
curl -H "Authorization: Bearer SEU_TOKEN_AQUI" http://localhost:3005/api/status
```

### 11.2) Importar o schema/tabelas via SQL (bootstrap rápido)

Se você quiser subir todas as tabelas rapidamente via SQL, há um dump em:
- `src/Kernel/Database/sweflow_db_2026-03-02_013709.sql`

PostgreSQL (exemplo):

```bash
psql "host=localhost port=5432 dbname=sweflow_db user=admin password=SENHA_FORTE_AQUI" \
  -f src/Kernel/Database/sweflow_db_2026-03-02_013709.sql
```

Depois disso, ainda é válido rodar:

```bash
php db migrate
```

---

## 12) Como criar uma NOVA tabela (migration) e aplicar no banco

As migrations são arquivos PHP que retornam um array com `up` e `down` (funções que recebem um `PDO`).

### 12.1) Criar migration dentro de um MÓDULO

Exemplo: criar tabela `exemplos` no módulo `Usuario` (ajuste para seu módulo).

Crie o arquivo:

`src/Modules/Usuario/Database/Migrations/2026_03_15_000001_create_exemplos_table.php`

Conteúdo:

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

### 12.2) Criar migration dentro de um PLUGIN

Para plugins, o caminho padrão é:

`plugins/sweflow-module-seu-plugin/src/Database/Migrations/<versao>/*.php`

Exemplo:

`plugins/sweflow-module-meu-plugin/src/Database/Migrations/1.0.0/create_meu_plugin_table.php`

Depois aplique:

```bash
php sweflow plugin:migrate
```

Ou, para rodar tudo (módulos + plugins):

```bash
php db migrate
```

---

## 13) Como ALTERAR uma tabela (migration) e aplicar no banco

Crie uma nova migration (não edite migrations antigas em produção).

Exemplo: adicionar coluna `descricao` na tabela `exemplos`:

`src/Modules/Usuario/Database/Migrations/2026_03_15_000002_add_descricao_to_exemplos.php`

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

Se precisar reverter a última migration de módulos:

```bash
php db rollback
```

---

## 14) Como adicionar dados (seed) em uma tabela

Seeders são arquivos PHP que retornam uma função `callable(PDO $pdo)`.

Exemplo: inserir dados iniciais em `exemplos`:

`src/Modules/Usuario/Database/Seeders/001_seed_exemplos.php`

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

Executar seeders:

```bash
php db seed
```

Recomendação prática: escreva seeders de forma idempotente (ex.: usar `INSERT ... ON CONFLICT` no PostgreSQL, ou validar existência antes de inserir).

---

## 15) Subir a aplicação

### 15.1) Rodar localmente (server embutido do PHP)

```bash
php -S 0.0.0.0:3005 index.php
```

### 15.2) Rodar em produção com PM2

Inicie o processo:

```bash
pm2 start php --name sweflow-api -- -S 0.0.0.0:3005 index.php
pm2 status
```

Persistir após reboot:

```bash
pm2 startup
pm2 save
```

Logs:

```bash
pm2 logs sweflow-api --lines 200
```

---

## 16) Checklist final de validação

1. API responde:
   - `curl -i http://localhost:3005/`
2. Banco conectado:
   - `curl -i http://localhost:3005/api/db-status`
3. Migrations aplicadas:
   - rode `php db migrate` e verifique saídas
4. Seeds (opcional):
   - `php db seed`
5. Produção:
   - `APP_DEBUG=false`
   - firewall liberando somente portas necessárias

