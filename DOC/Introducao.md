# Introdução

## O que é a Vupi.us API?

A **Vupi.us API** é uma plataforma de API modular construída em PHP 8.2, projetada para servir como base sólida, segura e extensível para aplicações web. Ela combina um kernel robusto com um sistema de módulos dinâmicos, permitindo que funcionalidades sejam adicionadas, removidas e gerenciadas em tempo de execução — sem necessidade de alterar o núcleo da aplicação.

O projeto nasceu com o objetivo de oferecer uma estrutura de API pronta para produção, com autenticação JWT, suporte a múltiplos bancos de dados, HTTPS automático via Caddy e um conjunto abrangente de proteções de segurança — tudo configurável via variáveis de ambiente e operável com um único comando de instalação.

---

## Propósito

A Vupi.us API foi concebida para resolver um problema comum no desenvolvimento de APIs PHP: a ausência de uma base que seja ao mesmo tempo simples de instalar, segura por padrão e extensível sem acoplamento. O projeto oferece:

- Uma API RESTful funcional desde o primeiro `composer install`
- Autenticação JWT com refresh tokens, revogação e rotação de chaves
- Um sistema de módulos que permite expandir a plataforma sem tocar no kernel
- Uma IDE integrada para criar e fazer deploy de novos módulos diretamente pela interface
- Segurança em múltiplas camadas, ativa por padrão em ambiente de produção

---

## Arquitetura

A aplicação segue uma arquitetura de **Kernel + Módulos** com **autenticação plugável**, onde o kernel fornece a infraestrutura base (roteamento, autenticação, banco de dados, middlewares, container de dependências) e os módulos encapsulam funcionalidades de negócio de forma independente.

O sistema de autenticação é baseado em **contratos (interfaces)**, permitindo que desenvolvedores substituam qualquer parte do pipeline — desde a extração do token até a resolução do usuário — sem modificar o kernel. Isso significa que você pode:

- Substituir JWT por OAuth2, SAML, LDAP ou sessão PHP
- Usar Active Directory, API externa ou qualquer fonte de usuários
- Implementar autorização customizada (ACL, RBAC, ABAC)
- Integrar múltiplos módulos de autenticação diferentes no mesmo projeto

```
index.php (entry point)
    └── Application::boot()
            ├── ModuleLoader::discover(src/Modules/)
            ├── ModuleLoader::bootAll()
            │       └── Módulos registram contratos no container
            │           (AuthContextInterface, UserResolverInterface, etc.)
            └── ModuleLoader::registerRoutes($router)
```

Cada módulo segue a mesma estrutura interna:

```
src/Modules/NomeModulo/
    ├── Controllers/
    ├── Services/
    ├── Repositories/
    ├── Entities/
    ├── Database/Migrations/
    ├── Routes/web.php
    └── NomeModuloProvider.php
```

O container de dependências resolve interfaces automaticamente, e os middlewares são compostos por rota — permitindo controle granular de autenticação, rate limiting e proteção de banco por endpoint.

---

## Módulos Nativos

A plataforma já vem com cinco módulos instalados por padrão:

| Módulo | Descrição |
|---|---|
| **Auth** | Autenticação JWT: login, logout, refresh token, recuperação de senha e verificação de e-mail |
| **Usuario** | Gerenciamento completo de usuários: registro, perfil, alteração de senha, upload de avatar e controle admin |
| **IdeModuleBuilder** | IDE integrada para criar, editar, executar e fazer deploy de módulos diretamente pela plataforma. Inclui configuração de banco de dados personalizado para isolamento de dados de desenvolvimento |
| **LinkEncurtador** | Encurtador de URLs com autenticação própria, analytics por link e controle de limites por usuário |
| **Documentacao** | Módulo de documentação integrado à plataforma |

Novos módulos podem ser criados via CLI (`php vupi make:module Nome`) ou diretamente pela IDE, e são carregados automaticamente na próxima requisição.

---

## Segurança

A segurança é tratada como requisito de primeira classe. A plataforma implementa múltiplas camadas de proteção ativas por padrão:

- **JWT com rotação de chaves** — suporte a múltiplos secrets (`JWT_SECRET_v1`, `JWT_SECRET_v2`) com identificação por KID, permitindo rotação sem invalidar sessões ativas
- **Rate limiting** — por IP, por usuário e por chave customizada, com suporte a Redis para ambientes distribuídos
- **Circuit breaker** — proteção do banco de dados com falha rápida quando o serviço está degradado
- **Threat scoring** — pontuação de ameaças baseada em comportamento (tentativas de login, IPs suspeitos)
- **Audit logging** — todos os eventos relevantes (401, 403, 429, ações admin) são registrados com IP, user agent e contexto
- **Bot blocker** — bloqueio de user agents maliciosos antes de qualquer processamento
- **Validação de segredos** — em produção, `JWT_SECRET` e `JWT_API_SECRET` exigem mínimo de 32 caracteres; senhas de banco remoto exigem mínimo de 16 caracteres
- **Headers de segurança** — CSP, X-Frame-Options, X-Content-Type-Options aplicados globalmente em todas as respostas
- **CORS** — whitelist de origens configurável via `CORS_ALLOWED_ORIGINS`
- **HTTPS enforcer** — redireciona HTTP para HTTPS quando `COOKIE_SECURE=true`

A fachada `Auth` simplifica a aplicação dessas proteções nas rotas dos módulos:

```php
// Rota pública com rate limit
$router->post('/api/contato', [Controller::class, 'enviar'], Auth::limit(5));

// Rota privada (qualquer usuário autenticado)
$router->get('/api/perfil', [Controller::class, 'perfil'], Auth::user());

// Rota admin com circuit breaker
$router->delete('/api/usuario/{id}', [Controller::class, 'deletar'], Auth::admin(db: true));

// Rota machine-to-machine
$router->post('/api/webhook', [Controller::class, 'receber'], Auth::api());
```

---

## Banco de Dados

A plataforma suporta **PostgreSQL** e **MySQL** de forma nativa, com suporte a duas conexões simultâneas — uma para o core e outra para módulos externos. O driver é configurado via variável de ambiente (`DB_CONEXAO=postgresql` ou `DB_CONEXAO=mysql`), e o sistema adapta automaticamente a sintaxe SQL conforme o driver ativo.

As migrações são versionadas e executadas via CLI:

```bash
php vupi migrate          # executa todas as migrations pendentes
php vupi migrate --seed   # migrations + seeders (cria admin padrão)
```

---

## Stack Tecnológico

| Camada | Tecnologia |
|---|---|
| Linguagem | PHP 8.2 |
| Banco de dados | PostgreSQL 15 / MySQL 8.0 |
| Autenticação | JWT via `firebase/php-jwt` |
| Email | PHPMailer |
| Proxy reverso | Caddy (HTTPS automático via Let's Encrypt) |
| Containerização | Docker + Docker Compose |
| Análise estática | PHPStan (nível 6) |
| Testes | PHPUnit |
| UUID | `ramsey/uuid` |
| Variáveis de ambiente | `vlucas/phpdotenv` |

---

## Instalação Rápida

Em um servidor Ubuntu 22.04 ou 24.04, a instalação completa é feita com um único comando:

```bash
git clone https://github.com/seu-repo/api_vupi.us_php.git
cd api_vupi.us_php
sudo bash install.sh
```

O script instala PHP 8.2, Docker, drivers de banco, Composer, sobe o banco via Docker Compose, executa migrations e seeders, e inicia o servidor — tudo automaticamente.

Para setup manual ou em outros ambientes, consulte o documento **Instalação e Configuração**.

---

## Estrutura da Documentação

Esta documentação está organizada nos seguintes documentos:

| Documento | Conteúdo |
|---|---|
| **Introdução** *(este arquivo)* | Visão geral, arquitetura e propósito |
| **Instalação e Configuração** | Setup completo, variáveis de ambiente e Docker |
| **Autenticação** | Sistema plugável, contratos, JWT, OAuth2, LDAP e exemplos práticos |
| **Módulos** | Como criar, instalar e gerenciar módulos (incluindo módulos de auth customizados) |
| **Configuração de Banco de Dados na IDE** | Como configurar banco de dados personalizado para isolamento de dados de desenvolvimento |
| **Endpoints** | Referência completa de todos os endpoints da API |
| **Segurança** | Rate limiting, circuit breaker, audit logging e boas práticas |
| **IDE** | Uso da IDE integrada para desenvolvimento de módulos |
| **CLI** | Referência de todos os comandos `php vupi` |
| **Deploy** | Caddy, PM2, Nginx e arquitetura de produção |

---

## Licença

MIT — Vupi.us API © 2026
