<?php

declare(strict_types=1);

namespace Src\Kernel\Auth;

use Src\Kernel\Contracts\TokenPayloadInterface;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Kernel\Contracts\UserResolverInterface;
use Src\Kernel\Database\DeveloperConnectionResolver;
use Src\Kernel\Database\PdoFactory;

/**
 * Resolve usuário tentando múltiplos repositórios em sequência.
 *
 * Pipeline:
 *   1. Repositório nativo (tabela `usuarios` no banco core)
 *   2. Repositório do módulo sendo acessado (ex: `roxxer_usuarios` no banco Aiven)
 *
 * Isso permite que módulos do desenvolvedor tenham suas próprias tabelas de usuários
 * e que o AuthHybridMiddleware funcione transparentemente para rotas desses módulos.
 *
 * O repositório do módulo é descoberto automaticamente:
 *   - Extrai o nome do módulo da URI (/api/roxxer/... → Roxxer)
 *   - Busca a classe Src\Modules\{Modulo}\Repositories\Usuario{Modulo}Repository
 *   - Se implementa UserRepositoryInterface, usa como fallback
 */
final class CompositeUserResolver implements UserResolverInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $primaryRepository
    ) {}

    public function resolve(string $identifier, TokenPayloadInterface $payload): mixed
    {
        // 1. Tenta o repositório nativo (banco core)
        $user = $this->primaryRepository->buscarPorUuid($identifier);
        if ($user !== null) {
            return $user;
        }

        // 2. Tenta o repositório do módulo sendo acessado
        $moduleRepo = $this->discoverModuleRepository();
        if ($moduleRepo !== null) {
            return $moduleRepo->buscarPorUuid($identifier);
        }

        return null;
    }

    /**
     * Descobre o repositório de usuários do módulo sendo acessado.
     * Retorna null se não encontrar ou se o módulo não tem repositório próprio.
     */
    private function discoverModuleRepository(): ?UserRepositoryInterface
    {
        $moduleName = $this->extractModuleNameFromUri();
        if ($moduleName === null) {
            return null;
        }

        // Tenta múltiplas convenções de nomes de repositório:
        //   1. Usuario{Modulo}Repository (ex: UsuarioRoxxerRepository)
        //   2. {Modulo}UsuarioRepository (ex: RoxxerUsuarioRepository)
        //   3. UserRepository (genérico)
        //   4. UsuarioRepository (genérico pt-BR)
        $candidates = [
            "Src\\Modules\\{$moduleName}\\Repositories\\Usuario{$moduleName}Repository",
            "Src\\Modules\\{$moduleName}\\Repositories\\{$moduleName}UsuarioRepository",
            "Src\\Modules\\{$moduleName}\\Repositories\\UserRepository",
            "Src\\Modules\\{$moduleName}\\Repositories\\UsuarioRepository",
        ];

        $repoClass = null;
        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, UserRepositoryInterface::class)) {
                $repoClass = $candidate;
                break;
            }
        }

        if ($repoClass === null) {
            return null;
        }

        try {
            // O repositório do módulo precisa do PDO do desenvolvedor (não do core)
            $devResolver = DeveloperConnectionResolver::instance();
            $pdo = $devResolver->resolve();

            if ($pdo === null) {
                // Sem conexão personalizada — tenta o banco core
                $pdo = PdoFactory::fromEnv('DB');
            }

            return new $repoClass($pdo);
        } catch (\Throwable $e) {
            error_log("[CompositeUserResolver] Erro ao criar repo do módulo {$moduleName}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Extrai o nome do módulo da URI atual.
     * /api/roxxer/login → Roxxer (PascalCase)
     */
    private function extractModuleNameFromUri(): ?string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $segments = explode('/', trim($uri, '/'));

        if (count($segments) < 2 || strtolower($segments[0]) !== 'api') {
            return null;
        }

        $slug = $segments[1];

        // Ignora módulos nativos
        static $native = ['auth', 'authenticador', 'usuario', 'documentacao', 'ide', 'system',
                          'audit', 'status', 'env', 'capabilities', 'modules', 'dashboard', 'health'];
        if (in_array(strtolower($slug), $native, true)) {
            return null;
        }

        // Converte para PascalCase: roxxer → Roxxer, meu-modulo → MeuModulo
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    }
}
