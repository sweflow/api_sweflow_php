# Guia rápido: criando módulos na API modular
## Visão geral (o que você precisa saber)
- Você só trabalha em `src/Modules/`. O kernel em `src/Kernel/` não precisa de mudanças.
- Cada módulo é auto-descoberto. Basta criar a pasta do módulo e um `Routes/web.php` declarando rotas.
- O container resolve dependências por convenção: interfaces terminando com `Interface` são mapeadas para a classe de mesmo nome (sem `Interface`) no mesmo namespace. Ex.: `UsuarioServiceInterface` -> `UsuarioService`.
- Se dois módulos precisarem falar entre si, defina a interface em `src/Kernel/Contracts/` e implemente no próprio módulo. Assim nenhum módulo conhece a classe do outro, só o contrato.
## Estrutura mínima (exemplo real do módulo Usuario)
```
src/Modules/Usuario/
    Controllers/
        UsuarioController.php
    Services/
        UsuarioServiceInterface.php
        UsuarioService.php
    Repositories/
        UsuarioRepositoryInterface.php
        UsuarioRepository.php
    Entities/
        Usuario.php                 <- entidade de domínio
    Routes/
        web.php                     <- define as rotas do módulo
```
### 1) Rotas em `Routes/web.php`
O loader lê esse arquivo e registra as rotas. No módulo Usuario:
```php
<?php

use Src\Middlewares\RouteProtectionMiddleware;
use Src\Modules\Usuario\Controllers\UsuarioController;
use Src\Routes\Route;

$protected = [RouteProtectionMiddleware::class];

Route::post('/api/criar/usuario', [UsuarioController::class, 'criar']);
Route::get('/api/usuarios', [UsuarioController::class, 'listar'], $protected);
Route::get('/api/usuario/{uuid}', [UsuarioController::class, 'buscar'], $protected);
Route::put('/api/usuario/atualizar/{uuid}', [UsuarioController::class, 'atualizar'], $protected);
Route::delete('/api/usuario/deletar/{uuid}', [UsuarioController::class, 'deletar'], $protected);
Route::patch('/api/usuario/{uuid}/desativar', [UsuarioController::class, 'desativar'], $protected);
Route::patch('/api/usuario/{uuid}/ativar', [UsuarioController::class, 'ativar'], $protected);
```
### 2) Convenção de interfaces (injeção automática)
Se você declarar `UsuarioServiceInterface` e implementar `UsuarioService` no mesmo namespace, o container entrega `UsuarioService` sempre que alguém pedir `UsuarioServiceInterface` — sem arquivo vinculativo. Exemplo prático (separando cada arquivo):
```php
// src/Modules/Usuario/Services/UsuarioServiceInterface.php
namespace Src\Modules\Usuario\Services;

interface UsuarioServiceInterface {}
```

```php
// src/Modules/Usuario/Services/UsuarioService.php
namespace Src\Modules\Usuario\Services;

class UsuarioService implements UsuarioServiceInterface {}
```

```php
// Em qualquer classe que receba UsuarioServiceInterface no construtor:
// public function __construct(UsuarioServiceInterface $service) { ... }
// o container instancia UsuarioService e injeta automaticamente.
```
### 3) Controller do módulo (UsuarioController)
- Injeta o serviço via interface.
- Usa `Response::json` para responder.
- Lê dados do corpo da requisição (`$request->body`) e parâmetros de rota (`$request->param('uuid')`).
Trecho real:
```php
// construtor
public function __construct(private UsuarioServiceInterface $service) {}

// ação de criação
public function criar($request): Response
{
    $data = $request->body ?? [];
    // valida campos, chama $this->service->criar(...)
    return Response::json(['status' => 'success'], 201);
}
```
### 4) Service (UsuarioService)
- Contém regra de negócio: valida unicidade de e-mail/username, altera senha, ativa/desativa, etc.
- Depende de `UsuarioRepositoryInterface`, que o container resolve para `UsuarioRepository`.
Trecho real (construtor + criar):
```php
public function __construct(private UsuarioRepositoryInterface $repository) {}

public function criar(Usuario $usuario): void
{
    if ($this->repository->emailExiste($usuario->getEmail())) {
        throw new DomainException('E-mail já cadastrado.');
    }
    if ($this->repository->usernameExiste($usuario->getUsername())) {
        throw new DomainException('Username já cadastrado.');
    }
    $this->repository->salvar($usuario);
}
```
### 5) Repository (UsuarioRepository)
- Lida com o banco via PDO (injeção automática do PDO compartilhado do container).
- Implementa a interface `UsuarioRepositoryInterface` e converte linhas em entidades `Usuario`.
Trecho real (construtor):
```php
public function __construct(\PDO $pdo)
{
    parent::__construct($pdo);
}
```
### 6) Entidade (Usuario)
- Fica em `Entities/Usuario.php` e representa o domínio (campos, validações, alteração de senha, ativar/desativar, etc.).
### 7) Protegendo rotas (middleware)
- Basta passar middlewares na rota (veja `$protected` no `web.php`).
- O middleware `RouteProtectionMiddleware` roda antes do controller nas rotas que o recebem.
## Contratos compartilhados entre módulos (quando Auth precisa de dados do Usuário)
1) Defina a interface no núcleo: `src/Kernel/Contracts/UserProviderInterface.php`.
2) Implemente no módulo Usuario: `UserProvider` implementa `UserProviderInterface` usando o repositório.
3) No módulo Auth, injete apenas `UserProviderInterface`; o container entrega `UserProvider` pela convenção de nome.
Isso evita acoplamento: Auth não conhece classes do módulo Usuario, apenas o contrato no núcleo.
## Checklist final
- Criar pasta do módulo em `src/Modules/MeuModulo/`.
- Criar `Routes/web.php` com as rotas (opcionalmente com middlewares).
- Implementar Controllers, Services, Repositories, Entities no seu módulo.
- Usar interfaces + convenção de nomes (Interface -> classe sem `Interface` no mesmo namespace). Sem configurações extras.
- Se precisar de contratos compartilhados entre módulos, coloque a interface em `src/Kernel/Contracts/` e implemente no seu módulo.
- Não alterar nada em `src/Kernel/` para criar módulos.
Pronto: o loader descobre o módulo automaticamente, registra rotas, resolve dependências e o front de status exibe as rotas detectadas.
