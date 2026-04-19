# Autenticação Plugável

## Visão Geral

A Vupi.us API implementa um sistema de autenticação completamente plugável baseado em **contratos (interfaces)**. Isso significa que desenvolvedores podem substituir **qualquer parte** do pipeline de autenticação — desde a extração do token até a resolução do usuário — sem modificar o kernel.

O sistema segue os princípios de **Ports & Adapters (Hexagonal Architecture)** e **Dependency Inversion**:

- O kernel define **portas** (contratos/interfaces)
- Os módulos fornecem **adaptadores** (implementações)
- Módulos de negócio consomem as portas sem saber qual adaptador está ativo

Isso permite cenários como:

- Substituir JWT por OAuth2, SAML, magic link ou sessão PHP
- Usar LDAP, Active Directory ou API externa para resolver usuários
- Implementar autorização baseada em ACL, RBAC ou ABAC
- Integrar múltiplos módulos de autenticação diferentes no mesmo projeto

---

## Arquitetura do Pipeline de Autenticação

O pipeline de autenticação é composto por **8 contratos independentes**, cada um com uma responsabilidade única:

```
Request
    ↓
TokenResolverInterface      → De onde vem o token?
    ↓
TokenValidatorInterface     → O token é válido?
    ↓
UserResolverInterface       → Quem é o usuário?
    ↓
IdentityFactoryInterface    → Como montar a identidade?
    ↓
AuthContextInterface        → Orquestrador do pipeline
    ↓
AuthorizationInterface      → O usuário pode fazer isso?
    ↓
AuthIdentityInterface       → Objeto de identidade tipado
```

Cada contrato pode ser substituído **independentemente** por um módulo externo.

---

## Contratos do Sistema de Autenticação

### 1. AuthContextInterface

**Responsabilidade:** Orquestrar o pipeline completo de autenticação.

**Métodos:**

```php
interface AuthContextInterface
{
    // Constantes para atributos do Request
    public const IDENTITY_KEY       = 'auth_identity';
    public const LEGACY_USER_KEY    = 'auth_user';
    public const LEGACY_PAYLOAD_KEY = 'auth_payload';

    /**
     * Resolve a identidade a partir do Request.
     * Retorna AuthIdentityInterface se autenticado, null se não há credencial válida.
     * Nunca lança exceção — falha silenciosa retorna null.
     */
    public function resolve(Request $request): ?AuthIdentityInterface;

    /**
     * Extrai a identidade já resolvida de um Request.
     * Retorna null se o Request não passou por autenticação.
     */
    public function identity(Request $request): ?AuthIdentityInterface;
}
```

**Implementação nativa:** `JwtAuthContext`

**Como substituir:**

```php
// MeuAuth/Providers/MeuAuthProvider.php
public function boot(ContainerInterface $container): void
{
    $container->bind(
        AuthContextInterface::class,
        MeuAuthContext::class,
        true
    );
}
```

---

### 2. AuthIdentityInterface

**Responsabilidade:** Representar a identidade autenticada de forma tipada e imutável.

**Métodos:**

```php
interface AuthIdentityInterface
{
    // Identificação
    public function id(): string|int|null;
    public function role(): ?string;
    public function type(): string; // 'user', 'api_token', 'guest', 'inactive', etc.

    // Verificações de estado
    public function isAuthenticated(): bool;
    public function isApiToken(): bool;
    public function isGuest(): bool;
    public function hasRole(string ...$roles): bool;

    // Escape hatches (low-level)
    public function user(): mixed;
    public function payload(): ?TokenPayloadInterface;
}
```

**Implementações nativas:**
- `AuthIdentity` — usuário autenticado
- `InactiveAuthIdentity` — usuário inativo (403)
- `NotFoundAuthIdentity` — usuário não encontrado (401)

**Tipos de identidade:**

| Tipo | Descrição | Status HTTP |
|---|---|---|
| `user` | Usuário humano autenticado e ativo | 200 |
| `api_token` | Token machine-to-machine | 200 |
| `guest` | Sem credencial válida | 401 |
| `inactive` | Credencial válida, conta inativa | 403 |
| `not_found` | Token válido, usuário não existe | 401 |

Módulos podem definir tipos adicionais: `service`, `bot`, `impersonated`, etc.

---

### 3. AuthorizationInterface

**Responsabilidade:** Decidir se uma identidade tem permissão para realizar uma ação.

**Métodos:**

```php
interface AuthorizationInterface
{
    /**
     * Verifica se a identidade tem permissão de administrador do sistema.
     */
    public function isAdmin(AuthIdentityInterface $identity, Request $request): bool;

    /**
     * Verifica se a identidade possui um dos papéis informados.
     */
    public function hasRole(AuthIdentityInterface $identity, string ...$roles): bool;
}
```

**Implementação nativa:** `JwtAuthContext` (implementa ambos `AuthContextInterface` e `AuthorizationInterface`)

**Como substituir:**

```php
public function boot(ContainerInterface $container): void
{
    $container->bind(
        AuthorizationInterface::class,
        MinhaAutorizacao::class,
        true
    );
}
```

---

### 4. TokenResolverInterface

**Responsabilidade:** Extrair o token bruto do Request.

**Métodos:**

```php
interface TokenResolverInterface
{
    /**
     * Extrai o token bruto do Request.
     * Retorna string vazia se não encontrar token.
     */
    public function resolve(Request $request): string;
}
```

**Implementações nativas:**
- `BearerTokenResolver` — lê de `Authorization: Bearer` ou `X-API-KEY`
- `CookieTokenResolver` — lê de cookie `auth_token` com fallback para Bearer
- `CompositeTokenResolver` — tenta múltiplas fontes em ordem de prioridade

**Como criar um resolver customizado:**

```php
final class QueryStringTokenResolver implements TokenResolverInterface
{
    public function resolve(Request $request): string
    {
        $token = $request->query['token'] ?? '';
        return is_string($token) && strlen($token) <= 2048 ? $token : '';
    }
}
```

**Como usar:**

```php
public function boot(ContainerInterface $container): void
{
    $container->bind(
        TokenResolverInterface::class,
        QueryStringTokenResolver::class,
        true
    );
}
```

---

### 5. TokenValidatorInterface

**Responsabilidade:** Validar o token e retornar o payload tipado.

**Métodos:**

```php
interface TokenValidatorInterface
{
    /**
     * Valida o token e retorna o payload tipado, ou null se inválido.
     * Nunca lança exceção — falha silenciosa retorna null.
     */
    public function validate(string $token): ?TokenPayloadInterface;

    /**
     * Indica se o token é um token de API puro (machine-to-machine).
     */
    public function isApiToken(string $token): bool;
}
```

**Implementação nativa:** `JwtTokenValidator`

**Como criar um validator customizado:**

```php
final class OAuth2TokenValidator implements TokenValidatorInterface
{
    public function __construct(
        private string $introspectionEndpoint,
        private string $clientId,
        private string $clientSecret
    ) {}

    public function validate(string $token): ?TokenPayloadInterface
    {
        // Chama o endpoint de introspecção do OAuth2
        $response = $this->introspect($token);
        
        if (!$response['active']) {
            return null;
        }

        return new OAuth2Payload($response);
    }

    public function isApiToken(string $token): bool
    {
        return str_starts_with($token, 'client_');
    }

    private function introspect(string $token): array
    {
        // Implementação da chamada HTTP ao servidor OAuth2
        // ...
    }
}
```

---

### 6. TokenPayloadInterface

**Responsabilidade:** Encapsular o payload do token de forma tipada.

**Métodos:**

```php
interface TokenPayloadInterface
{
    public function getSubject(): ?string;
    public function getRole(): ?string;
    public function isSignedWithApiSecret(): bool;
    public function get(string $key): mixed;
    public function raw(): mixed;
}
```

**Implementação nativa:** `JwtPayload`

---

### 7. UserResolverInterface

**Responsabilidade:** Buscar o usuário pelo identificador extraído do payload.

**Métodos:**

```php
interface UserResolverInterface
{
    /**
     * Resolve o usuário pelo identificador do payload.
     * Retorna null se não encontrado.
     */
    public function resolve(string $identifier, TokenPayloadInterface $payload): mixed;
}
```

**Implementação nativa:** `DatabaseUserResolver`

**Como criar um resolver customizado:**

```php
final class LdapUserResolver implements UserResolverInterface
{
    public function __construct(private LdapConnection $ldap) {}

    public function resolve(string $identifier, TokenPayloadInterface $payload): mixed
    {
        $dn = "uid={$identifier},ou=users,dc=example,dc=com";
        $result = ldap_read($this->ldap->connection(), $dn, "(objectClass=*)");
        
        if (!$result) {
            return null;
        }

        $entries = ldap_get_entries($this->ldap->connection(), $result);
        return $this->mapToUser($entries[0] ?? []);
    }

    private function mapToUser(array $entry): ?LdapUser
    {
        // Mapeia entrada LDAP para objeto de usuário
        // ...
    }
}
```

---

### 8. IdentityFactoryInterface

**Responsabilidade:** Criar objetos `AuthIdentityInterface` a partir de usuário e payload.

**Métodos:**

```php
interface IdentityFactoryInterface
{
    /**
     * Cria uma identidade para um usuário autenticado.
     */
    public function forUser(mixed $user, TokenPayloadInterface $payload): AuthIdentityInterface;

    /**
     * Cria uma identidade para um token de API puro (machine-to-machine).
     */
    public function forApiToken(): AuthIdentityInterface;
}
```

**Implementação nativa:** `DefaultIdentityFactory`

**Como criar uma factory customizada:**

```php
final class MinhaIdentityFactory implements IdentityFactoryInterface
{
    public function forUser(mixed $user, TokenPayloadInterface $payload): AuthIdentityInterface
    {
        // Token válido mas usuário não existe → 401
        if ($user === null) {
            return NotFoundAuthIdentity::instance();
        }

        // Usuário suspenso → 403 com tipo customizado
        if (method_exists($user, 'isSuspenso') && $user->isSuspenso()) {
            return new SuspendedAuthIdentity($user, $payload);
        }

        // Usuário inativo → 403
        if (method_exists($user, 'isAtivo') && !$user->isAtivo()) {
            return InactiveAuthIdentity::instance();
        }

        return AuthIdentity::forUser($user, $payload, $this->authorization);
    }

    public function forApiToken(): AuthIdentityInterface
    {
        return AuthIdentity::forApiToken();
    }
}
```

---

## Cenários de Uso

### Cenário 1: Substituir JWT por OAuth2

Um desenvolvedor quer usar OAuth2 em vez de JWT.

**Passo 1:** Criar o módulo `OAuth2Auth`

```
src/Modules/OAuth2Auth/
    ├── OAuth2TokenValidator.php
    ├── OAuth2Payload.php
    ├── OAuth2AuthContext.php
    └── OAuth2AuthProvider.php
```

**Passo 2:** Implementar os contratos

```php
// OAuth2Auth/OAuth2AuthContext.php
final class OAuth2AuthContext implements AuthContextInterface
{
    public function __construct(
        private TokenResolverInterface   $tokenResolver,
        private OAuth2TokenValidator     $tokenValidator,
        private UserResolverInterface    $userResolver,
        private IdentityFactoryInterface $identityFactory
    ) {}

    public function resolve(Request $request): ?AuthIdentityInterface
    {
        $token = $this->tokenResolver->resolve($request);
        if ($token === '') {
            return null;
        }

        $payload = $this->tokenValidator->validate($token);
        if ($payload === null) {
            return null;
        }

        $identifier = $payload->getSubject();
        if ($identifier === null) {
            return null;
        }

        $user = $this->userResolver->resolve($identifier, $payload);
        return $this->identityFactory->forUser($user, $payload);
    }

    public function identity(Request $request): ?AuthIdentityInterface
    {
        $identity = $request->attribute(self::IDENTITY_KEY);
        return $identity instanceof AuthIdentityInterface ? $identity : null;
    }
}
```

**Passo 3:** Registrar no provider

```php
// OAuth2Auth/OAuth2AuthProvider.php
public function boot(ContainerInterface $container): void
{
    // Substitui o validator
    $container->bind(
        TokenValidatorInterface::class,
        OAuth2TokenValidator::class,
        true
    );

    // Substitui o context
    $container->bind(
        AuthContextInterface::class,
        OAuth2AuthContext::class,
        true
    );
}
```

**Resultado:** Todos os módulos que usam `Auth::user()`, `Auth::admin()`, `Auth::identity()` continuam funcionando — agora com OAuth2 em vez de JWT.

---

### Cenário 2: Usar LDAP para resolver usuários

Um desenvolvedor quer autenticar usuários via LDAP em vez do banco de dados.

**Passo 1:** Criar o módulo `LdapAuth`

```
src/Modules/LdapAuth/
    ├── LdapUserResolver.php
    ├── LdapUser.php
    └── LdapAuthProvider.php
```

**Passo 2:** Implementar `UserResolverInterface`

```php
// LdapAuth/LdapUserResolver.php
final class LdapUserResolver implements UserResolverInterface
{
    public function __construct(private LdapConnection $ldap) {}

    public function resolve(string $identifier, TokenPayloadInterface $payload): mixed
    {
        $dn = "uid={$identifier},ou=users,dc=example,dc=com";
        $result = ldap_read($this->ldap->connection(), $dn, "(objectClass=*)");
        
        if (!$result) {
            return null;
        }

        $entries = ldap_get_entries($this->ldap->connection(), $result);
        $entry = $entries[0] ?? [];

        return new LdapUser(
            uid:   $entry['uid'][0] ?? '',
            name:  $entry['cn'][0] ?? '',
            email: $entry['mail'][0] ?? '',
            role:  $entry['role'][0] ?? 'user'
        );
    }
}
```

**Passo 3:** Registrar no provider

```php
// LdapAuth/LdapAuthProvider.php
public function boot(ContainerInterface $container): void
{
    $container->bind(
        UserResolverInterface::class,
        LdapUserResolver::class,
        true
    );
}
```

**Resultado:** O JWT continua sendo usado para validar tokens, mas os usuários são buscados no LDAP em vez do banco de dados.

---

### Cenário 3: Integrar dois módulos de autenticação diferentes

Um desenvolvedor cria:
- `MeuAuth` — módulo de autenticação com OAuth2
- `MeuUsers` — módulo de usuários com tabela própria
- `MeuEcommerce` — módulo de e-commerce que precisa de autenticação

**MeuAuth/MeuAuthProvider.php:**

```php
public function boot(ContainerInterface $container): void
{
    $container->bind(AuthContextInterface::class, OAuth2AuthContext::class, true);
    $container->bind(TokenValidatorInterface::class, OAuth2TokenValidator::class, true);
}
```

**MeuUsers/MeuUsersProvider.php:**

```php
public function boot(ContainerInterface $container): void
{
    $container->bind(UserRepositoryInterface::class, MeuUserRepository::class, true);
    $container->bind(UserResolverInterface::class, MeuUserResolver::class, true);
}
```

**MeuEcommerce/Routes/web.php:**

```php
use Src\Kernel\Auth;

$router->get('/api/pedidos', [PedidoController::class, 'listar'], Auth::user());
$router->post('/api/pedidos', [PedidoController::class, 'criar'], Auth::user());
```

**MeuEcommerce/Controllers/PedidoController.php:**

```php
public function criar(Request $request): Response
{
    $identity = Auth::identity($request);
    $userId   = Auth::id($request);
    $role     = Auth::role($request);

    // Funciona independente de qual módulo de auth está ativo
    $pedido = $this->service->criar($userId, $request->body());
    return Response::json(['data' => $pedido], 201);
}
```

**Resultado:** `MeuEcommerce` não sabe nada sobre `MeuAuth` ou `MeuUsers`. Ele só usa os contratos do kernel. Se amanhã o desenvolvedor trocar `MeuAuth` por outra solução, `MeuEcommerce` não muda uma linha.

---

## Fachada Auth

A fachada `Auth` simplifica o uso dos contratos nos controllers e rotas.

### Métodos para Rotas

```php
// Rota privada — qualquer usuário autenticado
Auth::user()

// Rota admin — admin_system com JWT_API_SECRET
Auth::admin()

// Rota machine-to-machine
Auth::api()

// Rota com autenticação opcional
Auth::optional()

// Rate limit sem autenticação
Auth::limit(5, window: 60)

// Circuit breaker sem autenticação
Auth::db(threshold: 5, cooldown: 20)

// Composição: autenticação + rate limit
Auth::user(limit: 10)

// Composição: admin + circuit breaker
Auth::admin(db: true)
```

### Métodos para Controllers

```php
// Retorna a identidade tipada
$identity = Auth::identity($request); // AuthIdentityInterface|null

// Retorna o objeto de usuário
$user = Auth::current($request); // mixed

// Retorna o ID do usuário
$id = Auth::id($request); // string|int|null

// Retorna o role/nível de acesso
$role = Auth::role($request); // string|null

// Retorna o tipo da identidade
$type = Auth::type($request); // 'user' | 'api_token' | 'guest' | 'inactive' | etc.

// Garante que o usuário está autenticado (lança 401 se não)
$identity = Auth::check($request);

// Garante que o usuário é admin (lança 403 se não)
$identity = Auth::checkAdmin($request);

// Garante que o usuário tem um dos papéis informados
$identity = Auth::checkRole($request, 'editor', 'moderador');
```

---

## Middlewares de Autenticação

Os middlewares usam os contratos para autenticar requisições. Eles não conhecem implementações concretas — apenas interfaces.

| Middleware | Descrição | Uso |
|---|---|---|
| `AuthHybridMiddleware` | Autentica via Bearer ou cookie | `Auth::user()` |
| `AdminOnlyMiddleware` | Verifica se é admin | `Auth::admin()` |
| `ApiTokenMiddleware` | Valida tokens de API | `Auth::api()` |
| `OptionalAuthHybridMiddleware` | Autentica se possível, não bloqueia | `Auth::optional()` |
| `AuthPageMiddleware` | Autentica para páginas HTML | Declaração direta |
| `AuthCookieMiddleware` | Autentica apenas via cookie | Declaração direta |

Todos os middlewares usam `$identity->type()` para decidir o comportamento — não há `instanceof` de classes concretas.

---

## Wiring no Container

O `index.php` registra os bindings nativos **após** o `boot()` dos módulos, permitindo que módulos externos sobrescrevam qualquer contrato.

```php
// index.php (simplificado)

// Boot dos módulos
$app->boot();

// Fallbacks nativos — só registram se nenhum módulo já registrou
if (!$container->hasBinding(TokenResolverInterface::class)) {
    $container->bind(TokenResolverInterface::class, BearerTokenResolver::class, true);
}

if (!$container->hasBinding(TokenValidatorInterface::class)) {
    $container->bind(TokenValidatorInterface::class, JwtTokenValidator::class, true);
}

if (!$container->hasBinding(UserResolverInterface::class)) {
    $container->bind(UserResolverInterface::class, DatabaseUserResolver::class, true);
}

if (!$container->hasBinding(IdentityFactoryInterface::class)) {
    $container->bind(IdentityFactoryInterface::class, DefaultIdentityFactory::class, true);
}

if (!$container->hasBinding(AuthContextInterface::class)) {
    $container->bind(AuthContextInterface::class, JwtAuthContext::class, true);
}

if (!$container->hasBinding(AuthorizationInterface::class)) {
    $container->bind(AuthorizationInterface::class, JwtAuthContext::class, true);
}
```

**Contratos substituíveis:**

```php
// src/Kernel/Nucleo/ModuleContainerProxy.php
private const OVERRIDABLE = [
    AuthContextInterface::class,
    AuthorizationInterface::class,
    TokenResolverInterface::class,
    TokenValidatorInterface::class,
    UserResolverInterface::class,
    IdentityFactoryInterface::class,
    UserRepositoryInterface::class,
    TokenBlacklistInterface::class,
];
```

Módulos externos podem sobrescrever qualquer um desses contratos livremente no `boot()` do provider.

---

## Boas Práticas

### 1. Sempre use os contratos, nunca as implementações

**Errado:**

```php
use Src\Kernel\Auth\JwtAuthContext;

$auth = new JwtAuthContext(...);
```

**Correto:**

```php
use Src\Kernel\Contracts\AuthContextInterface;

public function __construct(private AuthContextInterface $auth) {}
```

### 2. Use a fachada Auth nos controllers

**Errado:**

```php
$identity = $request->attribute('auth_identity');
$user = $request->attribute('auth_user');
```

**Correto:**

```php
$identity = Auth::identity($request);
$user = Auth::current($request);
```

### 3. Use `type()` em vez de `instanceof`

**Errado:**

```php
if ($identity instanceof InactiveAuthIdentity) {
    return Response::json(['error' => 'Conta inativa.'], 403);
}
```

**Correto:**

```php
if ($identity->type() === 'inactive') {
    return Response::json(['error' => 'Conta inativa.'], 403);
}
```

### 4. Delegue autorização para `AuthorizationInterface`

**Errado:**

```php
if ($identity->role() === 'admin_system') {
    // lógica admin
}
```

**Correto:**

```php
if ($identity->hasRole('admin_system')) {
    // lógica admin
}
```

O `hasRole()` delega para `AuthorizationInterface` quando disponível, permitindo que módulos customizem a lógica de roles.

### 5. Nunca acesse `$_COOKIE`, `$_SERVER` ou `$_SESSION` diretamente

**Errado:**

```php
$token = $_COOKIE['auth_token'] ?? '';
```

**Correto:**

```php
$token = $this->tokenResolver->resolve($request);
```

---

## Resumo

A arquitetura de autenticação plugável da Vupi.us API permite que desenvolvedores:

- **Substituam qualquer parte do pipeline** — token resolver, validator, user resolver, identity factory, authorization
- **Integrem múltiplos módulos** — um módulo de auth + um módulo de users + N módulos de negócio
- **Usem qualquer estratégia de autenticação** — JWT, OAuth2, SAML, LDAP, sessão, magic link, etc.
- **Implementem autorização customizada** — ACL, RBAC, ABAC, policies, etc.
- **Mantenham compatibilidade** — módulos de negócio usam contratos, não implementações

Tudo isso sem modificar uma linha do kernel.

