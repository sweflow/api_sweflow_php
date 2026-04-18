# Middlewares

## O que é um Middleware

Um middleware é uma camada intermediária no pipeline de processamento de uma requisição HTTP. Ele recebe o `Request`, pode inspecionar, modificar ou bloquear a requisição, e decide se passa o controle para o próximo middleware (ou para o controller) chamando `$next($request)`.

O modelo usado é o **onion model** — cada middleware envolve o próximo como camadas de uma cebola:

```
Requisição →  [MW 1]  →  [MW 2]  →  [MW N]  →  Controller
Resposta   ←  [MW 1]  ←  [MW 2]  ←  [MW N]  ←  Controller
```

O contrato é simples:

```php
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
```

---

## Middlewares Disponíveis

### AuthHybridMiddleware

Autentica a requisição via JWT. Aceita o token tanto pelo header `Authorization: Bearer <token>` quanto pelo cookie `auth_token`. É o middleware de autenticação padrão para rotas de API.

Fluxo interno:
1. Extrai o token do header ou cookie
2. Detecta se é um token de API puro (`tipo: 'api'`) — se sim, injeta `api_token: true` e passa adiante
3. Decodifica e valida o JWT (assinatura, claims, `iss`, `aud`, `sub`, `jti`, `exp`)
4. Verifica se o `jti` está na blacklist de tokens revogados
5. Busca o usuário no banco pelo `sub` (UUID)
6. Verifica se o usuário está ativo
7. Injeta `auth_user` e `auth_payload` no `Request`

Em caso de falha, retorna `401`. Se o usuário estiver inativo, retorna `403`.

```php
// Uso direto na rota
$router->get('/api/perfil', [Controller::class, 'perfil'], [AuthHybridMiddleware::class]);

// Via fachada Auth (recomendado)
$router->get('/api/perfil', [Controller::class, 'perfil'], Auth::user());
```

---

### AdminOnlyMiddleware

Deve ser usado **após** o `AuthHybridMiddleware`. Verifica se o usuário autenticado é `admin_system` e se o token foi assinado com `JWT_API_SECRET`.

A dupla verificação (payload + usuário no banco + secret correto) garante que um token de usuário comum assinado com `JWT_SECRET` nunca acessa rotas admin, mesmo que o campo `nivel_acesso` esteja correto no payload.

```php
// Uso direto
$router->delete('/api/usuario/{uuid}', [Controller::class, 'deletar'], [
    AuthHybridMiddleware::class,
    AdminOnlyMiddleware::class,
]);

// Via fachada Auth (recomendado)
$router->delete('/api/usuario/{uuid}', [Controller::class, 'deletar'], Auth::admin());
```

---

### ApiTokenMiddleware

Valida tokens JWT de API — tokens assinados com `JWT_API_SECRET` e com `tipo: 'api'` ou `api_access: true`. Usado para integrações machine-to-machine onde não há usuário envolvido.

Aceita o token via header `Authorization: Bearer` ou `X-API-KEY`.

```php
// Uso direto
$router->post('/api/webhook/receber', [Controller::class, 'receber'], [ApiTokenMiddleware::class]);

// Via fachada Auth (recomendado)
$router->post('/api/webhook/receber', [Controller::class, 'receber'], Auth::api());
```

---

### OptionalAuthHybridMiddleware

Tenta autenticar a requisição, mas não bloqueia se o token estiver ausente ou inválido. Útil para rotas que retornam conteúdo diferente para usuários logados e não logados.

Se o token for válido, injeta `auth_user` e `auth_payload` normalmente. Se não houver token ou o token for inválido, simplesmente passa adiante sem autenticação.

```php
// Via fachada Auth (recomendado)
$router->get('/api/produtos', [Controller::class, 'listar'], Auth::optional());
```

No controller, você verifica se o usuário está presente:

```php
$usuario = Auth::current($request); // null se não autenticado
if ($usuario !== null) {
    // retorna dados personalizados
}
```

---

### AuthPageMiddleware

Middleware de autenticação para rotas que retornam HTML. Em vez de retornar `401 JSON`, redireciona para `/` (ou `/ide/login` para rotas da IDE) quando o usuário não está autenticado.

Por padrão (`requireAdmin = true`), exige `admin_system` com `JWT_API_SECRET` — usado pelo dashboard. Com `requireAdmin = false`, aceita qualquer usuário autenticado — usado pela IDE.

```php
// Rota de página admin (dashboard)
$router->get('/dashboard', [DashboardController::class, 'index'], [AuthPageMiddleware::class]);

// Rota de página para qualquer usuário (IDE)
$router->get('/ide', [IdeController::class, 'index'], [
    new AuthPageMiddleware($usuarios, $blacklist, requireAdmin: false)
]);
```

---

### AuthCookieMiddleware

Variante do `AuthHybridMiddleware` que aceita **apenas** o cookie `auth_token`, ignorando o header `Authorization`. Usado em contextos onde o token só deve vir via cookie (ex: páginas com formulários).

```php
$router->get('/minha-pagina', [Controller::class, 'index'], [AuthCookieMiddleware::class]);
```

---

### RouteProtectionMiddleware

Middleware flexível que aceita tanto token de usuário quanto token de API, com suporte a restrição por papel via atributo `roles` no `Request`. Útil para rotas que precisam aceitar múltiplos tipos de token mas com controle de acesso por role.

---

### RateLimitMiddleware

Limita o número de requisições por janela de tempo. Aplica dois contadores independentes: um por IP e outro por usuário autenticado (quando disponível).

Parâmetros:

| Parâmetro | Tipo | Padrão | Descrição |
|---|---|---|---|
| `limit` | `int` | `60` | Máximo de requisições por janela (por IP) |
| `window` | `int` | `60` | Tamanho da janela em segundos |
| `key` | `string` | `''` | Chave customizada do contador (usa a URI se vazio) |
| `user_limit` | `int` | `= limit` | Limite separado para usuários autenticados |

Quando o limite é atingido, retorna `429` com os headers:

```
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1714000060
Retry-After: 45
```

Cada violação acumula `+20` pontos no `ThreatScorer` do IP.

```php
// Uso direto
$router->post('/api/contato', [Controller::class, 'enviar'], [
    [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60, 'key' => 'contato.enviar']],
]);

// Via fachada Auth (recomendado)
$router->post('/api/contato', [Controller::class, 'enviar'], Auth::limit(5, window: 60));

// Com autenticação + rate limit
$router->post('/api/posts', [Controller::class, 'criar'], Auth::user(limit: 20));

// Limites diferentes para IP e usuário autenticado
$router->post('/api/auth/login', [AuthController::class, 'login'], [
    [RateLimitMiddleware::class, ['limit' => 5, 'window' => 60, 'key' => 'auth.login', 'user_limit' => 5]],
]);
```

O storage usa Redis se `REDIS_HOST` estiver configurado (distribuído entre múltiplos nós), ou arquivo local caso contrário.

---

### CircuitBreakerMiddleware

Implementa o padrão Circuit Breaker para proteger o banco de dados (ou qualquer serviço externo) de falhas em cascata.

Estados:

| Estado | Comportamento |
|---|---|
| `CLOSED` | Operação normal — requisições passam |
| `OPEN` | Muitas falhas detectadas — rejeita imediatamente com `503` sem tocar o backend |
| `HALF` | Após o cooldown — deixa passar uma requisição de teste |

Parâmetros:

| Parâmetro | Tipo | Padrão | Descrição |
|---|---|---|---|
| `service` | `string` | `'default'` | Nome do serviço monitorado (usado como chave do estado) |
| `threshold` | `int` | `5` | Número de falhas consecutivas para abrir o circuito |
| `cooldown` | `int` | `30` | Segundos antes de tentar fechar o circuito (HALF) |

```php
// Uso direto
$router->post('/api/pedidos', [Controller::class, 'criar'], [
    [CircuitBreakerMiddleware::class, ['service' => 'database', 'threshold' => 5, 'cooldown' => 20]],
]);

// Via fachada Auth (recomendado)
$router->post('/api/pedidos', [Controller::class, 'criar'], Auth::admin(db: true));
$router->post('/api/pedidos', [Controller::class, 'criar'], Auth::db(threshold: 5, cooldown: 20));
```

O estado é compartilhado via Redis em ambientes distribuídos, ou via arquivo local em servidor único.

---

### BotBlockerMiddleware

Aplicado **globalmente** em toda requisição pela `Application`, antes do roteador. Bloqueia:

1. User agents de ferramentas de ataque conhecidas (`sqlmap`, `nikto`, `nmap`, `nuclei`, `burpsuite`, `acunetix`, etc.) — acumula `+50` pontos no ThreatScorer
2. Requisições para `/api/*` sem User-Agent — acumula `+15` pontos
3. IPs com score acumulado `>= 150` — bloqueio direto com `403`
4. IPs com score entre `50` e `149` — delay progressivo (2s, 5s ou 10s)

Em ambiente de desenvolvimento com IP loopback (`127.0.0.1`), o score acumulado e o delay são ignorados para não atrapalhar testes locais.

Este middleware **não precisa ser declarado nas rotas** — já está ativo para todas as requisições.

---

### SecurityHeadersMiddleware

Aplicado **globalmente** em toda requisição pela `Application`, após o roteador. Garante que os headers de segurança estejam presentes em todas as respostas, incluindo erros 404, 405 e respostas que escapem do pipeline de middlewares.

Headers aplicados:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; ...
Permissions-Policy: camera=(), microphone=(), geolocation=()
Strict-Transport-Security: max-age=31536000; includeSubDomains  (apenas HTTPS)
```

Não sobrescreve headers já definidos pela resposta — um controller pode definir um CSP customizado sem ser sobrescrito.

Este middleware **não precisa ser declarado nas rotas** — já está ativo para todas as requisições.

---

### HttpsEnforcerMiddleware

Aplicado **globalmente** pela `Application`. Bloqueia requisições HTTP quando `COOKIE_SECURE=true` e `COOKIE_HTTPONLY=true` estão configurados.

- Requisições de API recebem `403 JSON`
- Browsers recebem uma página HTML com link para a versão HTTPS

Este middleware **não precisa ser declarado nas rotas** — já está ativo para todas as requisições.

---

## Resumo: Middlewares Globais vs. Por Rota

| Middleware | Escopo | Declaração |
|---|---|---|
| `BotBlockerMiddleware` | Global (toda requisição) | Automático — não declarar |
| `SecurityHeadersMiddleware` | Global (toda requisição) | Automático — não declarar |
| `HttpsEnforcerMiddleware` | Global (toda requisição) | Automático — não declarar |
| `AuthHybridMiddleware` | Por rota | `Auth::user()` |
| `AdminOnlyMiddleware` | Por rota | `Auth::admin()` |
| `ApiTokenMiddleware` | Por rota | `Auth::api()` |
| `OptionalAuthHybridMiddleware` | Por rota | `Auth::optional()` |
| `AuthPageMiddleware` | Por rota (páginas HTML) | Declaração direta |
| `AuthCookieMiddleware` | Por rota | Declaração direta |
| `RateLimitMiddleware` | Por rota | `Auth::limit()` ou declaração direta |
| `CircuitBreakerMiddleware` | Por rota | `Auth::db()` ou declaração direta |
| `RouteProtectionMiddleware` | Por rota | Declaração direta |

---

## Como Usar Middlewares em um Módulo

### Via fachada `Auth` (recomendado)

A fachada `Auth` é a forma mais limpa de compor middlewares nas rotas. Ela retorna o array de middlewares já montado corretamente.

```php
<?php
// src/Modules/MeuModulo/Routes/web.php

use Src\Kernel\Auth;
use Src\Modules\MeuModulo\Controllers\ProdutoController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Rota pública — sem middleware
$router->get('/api/produtos', [ProdutoController::class, 'listar']);

// Rota pública com rate limit
$router->get('/api/produtos/buscar', [ProdutoController::class, 'buscar'], Auth::limit(30));

// Rota privada — qualquer usuário autenticado
$router->post('/api/produtos', [ProdutoController::class, 'criar'], Auth::user());

// Rota privada com rate limit
$router->post('/api/produtos', [ProdutoController::class, 'criar'], Auth::user(limit: 10));

// Rota admin
$router->delete('/api/produtos/{id}', [ProdutoController::class, 'deletar'], Auth::admin());

// Rota admin com proteção de banco
$router->delete('/api/produtos/{id}', [ProdutoController::class, 'deletar'], Auth::admin(db: true));

// Rota machine-to-machine
$router->post('/api/produtos/sync', [ProdutoController::class, 'sync'], Auth::api());

// Rota com autenticação opcional
$router->get('/api/produtos/{id}', [ProdutoController::class, 'detalhe'], Auth::optional());
```

### Via declaração direta

Quando precisar de configuração mais granular, declare os middlewares diretamente como array:

```php
<?php
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\RateLimitMiddleware;
use Src\Kernel\Middlewares\CircuitBreakerMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

// Rate limit customizado com chave específica
$router->post('/api/comentarios', [ComentarioController::class, 'criar'], [
    AuthHybridMiddleware::class,
    [RateLimitMiddleware::class, [
        'limit'      => 5,
        'window'     => 300,  // 5 por 5 minutos
        'key'        => 'comentarios.criar',
        'user_limit' => 3,    // usuários autenticados têm limite menor
    ]],
]);

// Múltiplos middlewares em sequência
$router->post('/api/pagamentos', [PagamentoController::class, 'processar'], [
    [RateLimitMiddleware::class, ['limit' => 3, 'window' => 60, 'key' => 'pagamentos']],
    [CircuitBreakerMiddleware::class, ['service' => 'pagamentos', 'threshold' => 3, 'cooldown' => 60]],
    AuthHybridMiddleware::class,
]);
```

### Acessando dados injetados no Controller

Os middlewares de autenticação injetam dados no `Request` via atributos. No controller, acesse-os assim:

```php
<?php

use Src\Kernel\Auth;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class ProdutoController
{
    public function criar(Request $request): Response
    {
        // Forma recomendada — via fachada Auth
        $usuario = Auth::current($request);   // AuthenticatableInterface ou null
        $uuid    = Auth::id($request);        // string UUID ou null
        $role    = Auth::role($request);      // 'admin_system' | 'usuario' | null

        // Lança DomainException(401) se não autenticado
        $usuario = Auth::check($request);

        // Lança DomainException(403) se não for admin_system
        $usuario = Auth::checkAdmin($request);

        // Verifica roles específicos
        $usuario = Auth::checkRole($request, 'editor', 'moderador');

        // Acesso direto ao payload JWT (quando precisar de claims customizados)
        $payload = $request->attribute('auth_payload');
        $jti     = $payload->jti ?? null;

        return Response::json(['ok' => true]);
    }
}
```

---

## Como Criar um Middleware Próprio

Quando os middlewares do kernel não atendem a um requisito específico do seu módulo, você pode criar o seu próprio. O processo é direto.

### 1. Implementar a interface

Crie o arquivo dentro do seu módulo e implemente `MiddlewareInterface`:

```php
<?php
// src/Modules/MeuModulo/Middlewares/VerificaAssinaturaMiddleware.php

namespace Src\Modules\MeuModulo\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class VerificaAssinaturaMiddleware implements MiddlewareInterface
{
    public function __construct(private string $secret)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $assinatura = $request->header('X-Webhook-Signature') ?? '';

        if (!$this->validar($assinatura, $request->rawBody ?? '')) {
            return Response::json(['error' => 'Assinatura inválida.'], 401);
        }

        return $next($request);
    }

    private function validar(string $assinatura, string $body): bool
    {
        $esperada = 'sha256=' . hash_hmac('sha256', $body, $this->secret);
        return hash_equals($esperada, $assinatura);
    }
}
```

### 2. Usar na rota

```php
<?php
// src/Modules/MeuModulo/Routes/web.php

use Src\Modules\MeuModulo\Controllers\WebhookController;
use Src\Modules\MeuModulo\Middlewares\VerificaAssinaturaMiddleware;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$webhookSecret = $_ENV['WEBHOOK_SECRET'] ?? '';

$router->post('/api/webhook/pagamento', [WebhookController::class, 'receber'], [
    [VerificaAssinaturaMiddleware::class, [$webhookSecret]],
]);
```

> Quando o middleware recebe parâmetros no construtor, passe-os como segundo elemento do array: `[MinhaClasse::class, [$param1, $param2]]`. O roteador instancia a classe com esses argumentos.

### 3. Middleware sem parâmetros

Se o middleware não precisa de parâmetros no construtor, basta passar a classe diretamente:

```php
$router->get('/api/recurso', [Controller::class, 'index'], [
    MeuMiddlewareSemParametros::class,
]);
```

---

## Exemplos Práticos

### Verificar plano do usuário

```php
<?php
// src/Modules/Assinatura/Middlewares/PlanoMiddleware.php

namespace Src\Modules\Assinatura\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Auth;

class PlanoMiddleware implements MiddlewareInterface
{
    public function __construct(private string $planoMinimo)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $usuario = Auth::current($request);

        if ($usuario === null) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        // Supondo que o usuário tenha um método getPlano()
        $plano = method_exists($usuario, 'getPlano') ? $usuario->getPlano() : 'free';

        $hierarquia = ['free' => 0, 'pro' => 1, 'enterprise' => 2];
        $nivelAtual = $hierarquia[$plano] ?? 0;
        $nivelMinimo = $hierarquia[$this->planoMinimo] ?? 0;

        if ($nivelAtual < $nivelMinimo) {
            return Response::json([
                'error' => 'Recurso disponível apenas no plano ' . $this->planoMinimo . '.',
                'upgrade_url' => '/planos',
            ], 403);
        }

        return $next($request);
    }
}
```

```php
// Uso na rota
$router->post('/api/relatorios/exportar', [RelatorioController::class, 'exportar'], [
    AuthHybridMiddleware::class,
    [PlanoMiddleware::class, ['pro']],
]);
```

### Log de auditoria customizado

```php
<?php
// src/Modules/MeuModulo/Middlewares/AuditarAcaoMiddleware.php

namespace Src\Modules\MeuModulo\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Auth;
use Src\Kernel\Support\AuditLogger;

class AuditarAcaoMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuditLogger $audit,
        private string $evento
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Só registra se a ação foi bem-sucedida
        if ($response->getStatusCode() < 400) {
            $this->audit->registrar(
                $this->evento,
                Auth::id($request),
                ['uri' => $request->getUri(), 'method' => $request->getMethod()]
            );
        }

        return $response;
    }
}
```

```php
// Uso na rota — o AuditLogger é resolvido pelo container automaticamente
$router->delete('/api/documentos/{id}', [DocumentoController::class, 'deletar'], [
    AuthHybridMiddleware::class,
    [AuditarAcaoMiddleware::class, ['documento.deletado']],
]);
```

> Quando o middleware depende de serviços do container (como `AuditLogger`), o roteador tenta instanciá-lo via container automaticamente se não houver parâmetros extras. Se houver parâmetros, a instanciação é manual — nesse caso, instancie o middleware no `boot()` do provider e passe a instância pronta para a rota.

### Middleware que modifica a resposta

Um middleware pode agir tanto antes quanto depois do handler — basta chamar `$next()` e trabalhar com a resposta retornada:

```php
<?php

namespace Src\Modules\MeuModulo\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class AdicionarVersaoMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Adiciona header de versão em todas as respostas deste módulo
        return $response->withHeader('X-API-Version', '2.1.0');
    }
}
```

---

## Boas Práticas

- Middlewares devem ter **responsabilidade única** — autenticação em um, rate limit em outro, auditoria em outro
- Prefira a fachada `Auth` para os casos comuns — ela garante a ordem correta dos middlewares
- Middlewares que dependem de serviços do container devem receber esses serviços via construtor (injeção de dependência), não via `$_ENV` ou globais
- Nunca relance exceções de autenticação — retorne `Response::json(['error' => '...'], 401)` diretamente
- Falhas em serviços auxiliares (log, auditoria, métricas) devem ser silenciosas — use `try/catch` e não deixe que um erro de observabilidade quebre a resposta
- Middlewares de módulos externos são executados dentro de `try/catch` pelo `ModuleGuard` — um erro não derruba o sistema, mas a rota pode não funcionar corretamente
