# Sobre a API Modular Sweflow

## Por que esta API existe?
Você quer entregar features rápido e com times paralelos sem quebrar o núcleo. Esta API foi desenhada para que cada módulo viva isolado em `src/Modules/`, enquanto o kernel (roteador, DI, HTTP, middlewares) fica intocado em `src/Kernel/`. Assim, equipes podem criar e publicar módulos sem mexer em infraestrutura.

## Conceito-chave: núcleo estável, módulos plugáveis
- **Núcleo (Kernel)**: roteador, container DI com convenção Interface→Classe, HTTP request/response, middlewares, loaders, views base. Fica em `src/Kernel/` e não exige alterações para novos módulos.
- **Módulos**: tudo que é feature (Controllers, Services, Repositories, Entities, rotas). Ficam em `src/Modules/` e são auto-descobertos. Cada módulo traz seu `Routes/web.php` e suas classes; nada mais é necessário.
- **Desacoplamento por contrato**: quando um módulo depende de outro, ele depende de uma interface definida no kernel (`src/Kernel/Contracts/`). Quem implementa é o módulo. O consumidor não conhece a classe concreta, só o contrato.

## Como os módulos são carregados
- O `ModuleLoader` varre `src/Modules/` e lê `Routes/web.php` de cada módulo.
- As rotas são registradas no roteador central com seus middlewares.
- O container resolve dependências por convenção: `AlgoInterface` → `Algo` no mesmo namespace. Sem arquivos vinculativos.

## Exemplo rápido (módulo Usuário)
Estrutura:
```
src/Modules/Usuario/
  Controllers/UsuarioController.php
  Services/UsuarioServiceInterface.php
  Services/UsuarioService.php
  Repositories/UsuarioRepositoryInterface.php
  Repositories/UsuarioRepository.php
  Entities/Usuario.php
  Routes/web.php
```
Rotas (`Routes/web.php`):
```php
use Src\Routes\Route;
use Src\Modules\Usuario\Controllers\UsuarioController;
use Src\Middlewares\RouteProtectionMiddleware;

$protected = [RouteProtectionMiddleware::class];

Route::post('/api/criar/usuario', [UsuarioController::class, 'criar']);
Route::get('/api/usuarios', [UsuarioController::class, 'listar'], $protected);
Route::get('/api/usuario/{uuid}', [UsuarioController::class, 'buscar'], $protected);
```
Injeção por convenção:
```php
// Interface
namespace Src\Modules\Usuario\Services;
interface UsuarioServiceInterface {}

// Implementação
namespace Src\Modules\Usuario\Services;
class UsuarioService implements UsuarioServiceInterface {}

// Em qualquer classe
// public function __construct(UsuarioServiceInterface $service) { ... }
// o container entrega UsuarioService automaticamente.
```

## Contratos compartilhados entre módulos (exemplo Auth -> Usuário)
1) Defina a interface no kernel (`src/Kernel/Contracts/UserProviderInterface.php`).
2) Implemente no módulo Usuário (`UserProvider` implementa a interface usando o repositório local).
3) No módulo Auth, injete apenas `UserProviderInterface`. O container entrega `UserProvider` pela convenção.
Resultado: Auth não conhece as classes do módulo Usuário, só o contrato.

## Benefícios
- **Produtividade**: criar módulo = pasta + rotas + classes. Sem mexer no núcleo.
- **Segurança**: middlewares por rota; rota protegida só declarando o middleware no array.
- **Escalabilidade de times**: cada time trabalha em seu módulo; o kernel permanece estável.
- **Testabilidade**: dependências por interface facilitam mocks e testes unitários.

## Como criar seu módulo agora
1) Crie `src/Modules/SeuModulo/`.
2) Crie `Routes/web.php` e registre rotas com `Route::get/post/...` (adicione middlewares se necessário).
3) Implemente Controllers, Services, Repositories, Entities. Use interfaces para o container aplicar a convenção.
4) Se precisar de um contrato compartilhado, coloque a interface em `src/Kernel/Contracts/` e implemente no seu módulo.

Pronto: o loader descobre, o roteador registra, o container injeta. Sem configurações extras.
