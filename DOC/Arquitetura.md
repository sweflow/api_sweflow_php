# Arquitetura

## Visão Geral

A Vupi.us API é construída sobre uma arquitetura de **Kernel + Módulos**, onde o kernel fornece toda a infraestrutura base da aplicação e os módulos encapsulam funcionalidades de negócio de forma independente e isolada.

O entry point é o `index.php`, que inicializa o container de dependências, instancia a `Application`, executa o boot dos módulos e despacha a requisição HTTP.

```
index.php
    ├── Dotenv::load()                  # carrega .env
    ├── Container::bind(...)            # registra dependências
    ├── Application::boot()
    │       ├── ModuleLoader::discover(src/Modules/)
    │       ├── ModuleLoader::bootAll()
    │       └── ModuleLoader::registerRoutes($router)
    └── Application::run()
            ├── RequestFactory::fromGlobals()
            ├── BotBlockerMiddleware
            ├── SecurityHeadersMiddleware
            └── Router::dispatch($request)
```

---

## Camadas da Arquitetura

```
┌─────────────────────────────────────────────────────────┐
│                        index.php                        │  Entry Point
├─────────────────────────────────────────────────────────┤
│                      Application                        │  Boot + Run
├──────────────┬──────────────────────┬───────────────────┤
│   Container  │       Router         │   ModuleLoader    │  Núcleo
├──────────────┴──────────────────────┴───────────────────┤
│                     Middlewares                         │  Pipeline HTTP
├─────────────────────────────────────────────────────────┤
│              Controllers  /  Services                   │  Lógica de Negócio
├─────────────────────────────────────────────────────────┤
│           Repositories  /  Entities                     │  Acesso a Dados
├─────────────────────────────────────────────────────────┤
│                    PdoFactory / PDO                     │  Banco de Dados
├─────────────────────────────────────────────────────────┤
│                      Support                            │  Serviços Transversais
└─────────────────────────────────────────────────────────┘
```

---

## Núcleo (`src/Kernel/Nucleo/`)

O núcleo é o coração da aplicação. Contém as classes responsáveis pelo ciclo de vida completo de cada requisição.

### Application

`Application` é a classe central que orquestra o boot e a execução da aplicação. Ela:

1. Registra o handler global de exceções
2. Chama `ModuleLoader::discover()` para encontrar todos os módulos disponíveis
3. Chama `ModuleLoader::bootAll()` para inicializar cada módulo habilitado
4. Chama `ModuleLoader::registerRoutes()` para registrar as rotas de cada módulo no roteador
5. No `run()`, constrói a requisição, passa pelo pipeline de segurança global e despacha para o roteador

O pipeline global aplicado em toda requisição, antes mesmo de chegar ao roteador, é:

```
BotBlockerMiddleware → SecurityHeadersMiddleware → Router::dispatch()
```

Respostas com status 401, 403 e 429 são automaticamente registradas no `AuditLogger` após o dispatch.

### Container (Dependency Injection)

`Container` é o container de injeção de dependências da aplicação. Ele resolve dependências automaticamente via Reflection, com suporte a:

- **Singletons** — instâncias compartilhadas durante o ciclo de vida da requisição
- **Bindings** — mapeamento de interfaces para implementações concretas
- **Auto-wiring** — resolução automática de dependências pelo tipo declarado no construtor
- **Convenção de nome** — `FooInterface` resolve automaticamente para `Foo` no mesmo namespace se não houver binding explícito
- **Detecção de dependência circular** — lança `RuntimeException` com o caminho completo do ciclo

```php
// Binding de interface para implementação
$container->bind(UserRepositoryInterface::class, UsuarioRepository::class, singleton: true);

// Resolução automática
$repo = $container->make(UserRepositoryInterface::class);
```

O container suporta clonagem — ao clonar, as instâncias singleton são descartadas mas os bindings são preservados. Isso é usado pelo `ModuleLoader` para criar containers isolados para módulos que usam a conexão secundária de banco.

### Router

`Router` implementa o roteamento HTTP da aplicação. Suporta os métodos `GET`, `POST`, `PUT`, `PATCH` e `DELETE`. Cada rota pode ter uma pilha de middlewares composta de forma declarativa.

O despacho funciona como um pipeline de middlewares em cadeia (onion model):

```
Middleware 1 → Middleware 2 → Middleware N → Controller::action()
```

Parâmetros de rota são extraídos via regex nomeada (`{id}`, `{uuid}`, `{alias}`) e injetados no `Request`. O handler pode ser um array `[Controller::class, 'method']` ou qualquer callable.

```php
// Rota com parâmetro e middlewares
$router->get('/api/links/{id}', [LinkController::class, 'buscar'], Auth::user());

// Rota pública com rate limit
$router->post('/api/auth/login', [AuthController::class, 'login'], [
    [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60]],
]);
```

### ModuleLoader

`ModuleLoader` é responsável por descobrir, carregar e gerenciar o ciclo de vida dos módulos. Ele varre múltiplos diretórios em busca de módulos:

1. `src/Modules/` — módulos nativos do sistema
2. `vendor/vupi.us/` — módulos instalados via Composer
3. `storage/modules/` — módulos instalados via marketplace
4. `plugins/` — módulos em desenvolvimento local

Para cada módulo encontrado, o loader tenta instanciar o `ModuleProviderInterface` correspondente. Se o módulo não tiver um provider explícito, usa `SimpleModuleProvider` como adaptador genérico.

O estado de cada módulo (habilitado/desabilitado) é persistido em `storage/modules_state.json`. Módulos protegidos (`Auth`, `Usuario`) não podem ser desabilitados.

Em produção, o loader usa um cache JSON (`storage/modules_cache.json`) para evitar o overhead de varredura de diretórios a cada requisição.

### ModuleGuard

`ModuleGuard` é a camada de isolamento e segurança para módulos externos. Antes de registrar qualquer módulo de terceiros, o guard valida:

- **Nome reservado** — módulos não podem usar nomes como `Auth`, `Usuario`, `Kernel`, `Core`
- **Formato do nome** — apenas PascalCase alfanumérico (máx. 64 chars)
- **Path traversal** — o caminho do módulo deve existir e ser acessível
- **Namespaces proibidos** — módulos não podem declarar `Src\Kernel\*` ou namespaces de módulos protegidos
- **URIs reservadas** — módulos não podem registrar rotas em prefixos do sistema (`/api/auth/`, `/dashboard`, `/api/system/`, etc.)

O boot e o carregamento de rotas de módulos externos são executados dentro de `try/catch` via `safeBoot()` e `safeLoadRoutes()` — um erro em um módulo externo não derruba o sistema.

### CapabilityResolver

`CapabilityResolver` implementa o sistema de capabilities — um mecanismo que permite que múltiplos módulos declarem suporte a uma mesma funcionalidade (ex: `email-sender`), mas apenas um seja o provider ativo por vez.

Cada módulo declara suas capabilities no `plugin.json`:

```json
{
  "provides": ["email-sender"]
}
```

O resolver persiste o mapeamento `capability → provider` em `storage/capabilities_registry.json`. Se dois módulos declaram a mesma capability, o dashboard permite escolher qual é o ativo. O `ModuleLoader` usa o resolver para decidir se um módulo deve ser carregado ou ignorado.

---

## Camada HTTP (`src/Kernel/Http/`)

### Request

`Request` encapsula todos os dados da requisição HTTP: método, URI, headers, body, parâmetros de rota e atributos injetados pelos middlewares (como `auth_user`). É construído uma única vez por `RequestFactory::fromGlobals()` e passado imutavelmente pelo pipeline.

### Response

`Response` encapsula a resposta HTTP. Oferece construtores estáticos para os tipos mais comuns:

```php
Response::json(['data' => $result], 200);
Response::html($htmlString, 200);
```

---

## Middlewares (`src/Kernel/Middlewares/`)

Os middlewares implementam `MiddlewareInterface` e são compostos por rota. Cada middleware recebe o `Request` e um `$next` callable, podendo interceptar antes e depois do handler.

| Middleware | Função |
|---|---|
| `AuthHybridMiddleware` | Autentica via JWT (header `Authorization` ou cookie) |
| `AdminOnlyMiddleware` | Verifica se o usuário autenticado tem role `admin_system` |
| `ApiTokenMiddleware` | Valida tokens de API assinados com `JWT_API_SECRET` |
| `OptionalAuthHybridMiddleware` | Injeta o usuário se autenticado, mas não bloqueia se não estiver |
| `AuthPageMiddleware` | Autentica para páginas HTML; redireciona para login se não autenticado |
| `RateLimitMiddleware` | Limita requisições por IP, por usuário ou por chave customizada |
| `CircuitBreakerMiddleware` | Proteção do banco de dados — falha rápida quando o serviço está degradado |
| `BotBlockerMiddleware` | Bloqueia user agents maliciosos antes de qualquer processamento |
| `HttpsEnforcerMiddleware` | Redireciona HTTP para HTTPS quando `COOKIE_SECURE=true` |
| `SecurityHeadersMiddleware` | Aplica headers de segurança em todas as respostas (CSP, X-Frame-Options, etc.) |
| `RouteProtectionMiddleware` | Proteção adicional de rotas sensíveis |

A fachada `Auth` simplifica a composição de middlewares nas rotas dos módulos:

```php
Auth::user()                    // AuthHybridMiddleware
Auth::admin()                   // AuthHybridMiddleware + AdminOnlyMiddleware
Auth::api()                     // ApiTokenMiddleware
Auth::optional()                // OptionalAuthHybridMiddleware
Auth::user(limit: 10)           // RateLimitMiddleware + AuthHybridMiddleware
Auth::admin(db: true)           // CircuitBreakerMiddleware + AuthHybridMiddleware + AdminOnlyMiddleware
Auth::limit(5, window: 60)      // RateLimitMiddleware (sem autenticação)
```

---

## Banco de Dados (`src/Kernel/Database/`)

### PdoFactory

`PdoFactory` cria conexões PDO a partir das variáveis de ambiente. Suporta PostgreSQL e MySQL com detecção automática de driver. Se o banco não existir, tenta criá-lo automaticamente antes de lançar erro.

Aceita dois prefixos:
- `DB` — conexão principal (core + módulos nativos)
- `DB2` — conexão secundária (módulos externos, driver pode ser diferente)

### ModuleConnectionResolver

`ModuleConnectionResolver` determina qual conexão PDO usar para cada módulo, lendo o arquivo `Database/connection.php` do módulo:

```php
// src/Modules/MeuModulo/Database/connection.php
return 'core';    // usa DB_* (padrão)
return 'modules'; // usa DB2_*
return 'auto';    // usa DEFAULT_MODULE_CONNECTION do .env
```

### Migrações

As migrações do kernel ficam em `src/Kernel/Database/migrations/` e são executadas automaticamente pelo `MigrateCommand`. Cada módulo tem sua própria pasta `Database/Migrations/` com arquivos PHP versionados.

As tabelas do kernel são:

| Tabela | Descrição |
|---|---|
| `audit_logs` | Registro de eventos de segurança com IP, user agent e contexto |
| `login_attempts` | Tentativas de login para detecção de brute force |
| `email_history` | Histórico de e-mails enviados |
| `email_throttle` | Rate limit de envio de e-mails |

---

## Módulos (`src/Modules/`)

Cada módulo é uma unidade autônoma que segue a estrutura:

```
src/Modules/NomeModulo/
    ├── Controllers/          # Recebem Request, retornam Response
    ├── Services/             # Lógica de negócio
    ├── Repositories/         # Acesso ao banco via PDO
    ├── Entities/             # Entidades de domínio
    ├── Database/
    │   ├── Migrations/       # Arquivos PHP versionados
    │   └── connection.php    # 'core' | 'modules' | 'auto'
    ├── Routes/
    │   └── web.php           # Definição de rotas do módulo
    └── NomeModuloProvider.php  # Implementa ModuleProviderInterface
```

O `ModuleProviderInterface` define o contrato que todo módulo deve implementar:

```php
interface ModuleProviderInterface
{
    public function registerRoutes(RouterInterface $router): void;
    public function boot(ContainerInterface $container): void;
    public function describe(): array;
    public function getName(): string;
    public function onInstall(): void;
    public function onEnable(): void;
    public function onDisable(): void;
    public function onUninstall(): void;
}
```

O método `boot()` é chamado uma vez por requisição para registrar bindings no container. O `registerRoutes()` registra as rotas do módulo no roteador global.

---

## Camada de Suporte (`src/Kernel/Support/`)

Serviços transversais usados por múltiplas camadas da aplicação.

### RequestContext

Singleton imutável criado uma vez por requisição. É o único ponto de verdade para informações do contexto HTTP: IP do cliente, HTTPS, proxy trust, request ID. Não depende de nenhuma outra classe do sistema.

```php
$ctx->getRequestId();  // ID único da requisição (hex 32 chars)
$ctx->getClientIp();   // IP real do cliente (resolve X-Forwarded-For se TRUST_PROXY=true)
$ctx->isSecure();      // true se HTTPS
```

### JwtDecoder

Centraliza toda a lógica de decodificação e validação de tokens JWT. Implementa:

- **Dois secrets** — `JWT_SECRET` (usuários) e `JWT_API_SECRET` (admin/API)
- **Key rotation via KID** — suporte a `JWT_SECRET_v1`, `JWT_SECRET_v2`, etc., permitindo rotação sem invalidar sessões ativas
- **Validação de algoritmo** — previne ataques de confusão de algoritmo (`alg:none`, RS256 injetado, etc.)
- **Validação de claims** — `iss`, `aud`, `sub` (UUID), `jti` (UUID), `nbf`, `exp`

### AuditLogger

Registra eventos de segurança na tabela `audit_logs` e em `stderr` (para integração com Fail2Ban e sistemas de log). Detecta automaticamente padrões de brute force (10+ falhas de login em 5 minutos) e emite alertas via `stderr` ou webhook HTTPS configurável via `SECURITY_ALERT_WEBHOOK`.

Campos sensíveis (`senha`, `password`, `token`, `secret`) são removidos do contexto antes de persistir.

### ThreatScorer

Sistema de pontuação de ameaças por IP. Acumula pontos baseado em comportamento suspeito e aplica delays progressivos ou bloqueio:

| Evento | Pontos |
|---|---|
| Honeypot hit | +100 |
| User agent malicioso | +50 |
| Falha de login | +30 |
| Rate limit atingido | +20 |
| Sem user agent | +15 |

| Score | Ação |
|---|---|
| >= 50 | Delay progressivo (2s → 5s → 10s) |
| >= 150 | Bloqueio (403) |

O score expira automaticamente após 1 hora. Suporta Redis (distribuído) ou arquivo (servidor único).

### IdempotencyLock

Proteção contra race conditions em operações críticas. Implementa distributed lock usando Redis (`SETNX` atômico) ou `flock` em arquivo. Também protege contra replay de operações idempotentes dentro de uma janela de tempo configurável.

```php
$lock = IdempotencyLock::acquire("pagamento:{$uuid}", ttl: 30);
if (!$lock) {
    return Response::json(['error' => 'Operação em andamento.'], 409);
}
try {
    // operação crítica
} finally {
    $lock->release();
}
```

### MailerService / EmailThrottle

`MailerService` encapsula o PHPMailer para envio de e-mails transacionais. `EmailThrottle` aplica rate limit de envio por endereço de e-mail, prevenindo abuso de endpoints de verificação e recuperação de senha.

---

## Contratos (`src/Kernel/Contracts/`)

Interfaces que definem os contratos entre as camadas. Permitem que implementações sejam trocadas sem alterar o código que as consome.

| Interface | Descrição |
|---|---|
| `ContainerInterface` | Container de injeção de dependências |
| `RouterInterface` | Roteador HTTP |
| `MiddlewareInterface` | Middleware do pipeline HTTP |
| `ModuleProviderInterface` | Provider de módulo |
| `AuthenticatableInterface` | Entidade autenticável (usuário) |
| `UserRepositoryInterface` | Repositório de usuários |
| `TokenBlacklistInterface` | Blacklist de tokens revogados |
| `RateLimitStorageInterface` | Storage de rate limit (Redis ou File) |
| `EmailSenderInterface` | Serviço de envio de e-mail |
| `TenantResolverInterface` | Resolução de tenant (multi-tenancy) |

---

## Fluxo Completo de uma Requisição

```
1. index.php recebe a requisição
2. Preflight CORS (OPTIONS) → responde 204 e encerra
3. Validação de segredos críticos (produção)
4. Application::run()
   a. RequestFactory::fromGlobals() → constrói Request
   b. BotBlockerMiddleware → bloqueia UAs maliciosos (403)
   c. HttpsEnforcerMiddleware → redireciona HTTP → HTTPS
   d. SecurityHeadersMiddleware → injeta headers de segurança
   e. Router::dispatch($request)
      i.  Encontra a rota correspondente (método + URI)
      ii. Monta o pipeline de middlewares da rota
      iii. Executa o pipeline (rate limit → circuit breaker → auth → controller)
      iv. Controller retorna Response
   f. AuditLogger registra 401/403/429 automaticamente
5. Response::send() → envia headers + body
```

---

## Escalabilidade Horizontal

A arquitetura suporta múltiplos nós (containers/VMs) com configuração mínima:

- **Redis** — configurar `REDIS_HOST` centraliza rate limiting, ThreatScorer, IdempotencyLock e CircuitBreaker entre todos os nós automaticamente
- **Volume compartilhado** — montar `/storage` em NFS/EFS/GlusterFS para compartilhar `capabilities_registry.json`, `modules_state.json` e uploads de imagem entre nós
- **Uploads** — podem ser migrados para S3/R2 via módulo de storage externo

```
Cloudflare
    ↓
Caddy (TLS + proxy)
    ↓
Nginx (roteamento interno)
    ↓
Vupi.us API (múltiplos nós PM2)
    ↓
PostgreSQL / Redis
```
