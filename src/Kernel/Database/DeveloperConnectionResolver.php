<?php

declare(strict_types=1);

namespace Src\Kernel\Database;

use PDO;
use Src\Modules\IdeModuleBuilder\Repositories\DatabaseConnectionRepository;
use Src\Modules\IdeModuleBuilder\Services\DatabaseConnectionService;

/**
 * Ponto único e confiável para resolver a conexão personalizada do desenvolvedor.
 *
 * Centraliza toda a lógica de:
 *   1. Extrair o userId do token JWT (Bearer header OU cookie auth_token)
 *   2. Buscar a conexão ativa na tabela ide_database_connections
 *   3. Criar e cachear o PDO personalizado
 *
 * Garante que, se o desenvolvedor configurou uma conexão na IDE,
 * ela será usada de forma consistente em TODOS os pontos do sistema.
 *
 * Uso:
 *   $resolver = DeveloperConnectionResolver::instance();
 *   $pdo = $resolver->resolve();          // null se não houver conexão ativa
 *   $pdo = $resolver->resolveOrDefault(); // fallback para PdoFactory::fromEnv('DB')
 */
final class DeveloperConnectionResolver
{
    private static ?self $instance = null;

    /** PDO personalizado cacheado para esta request */
    private ?PDO $cachedPdo = null;

    /** Flag para evitar re-tentativas quando não há conexão ativa */
    private bool $resolved = false;

    /** userId extraído do token (cacheado) */
    private ?string $userId = null;
    private bool $userIdResolved = false;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Resolve a conexão personalizada do desenvolvedor.
     * Retorna null se não houver conexão ativa ou se o usuário não estiver autenticado.
     */
    public function resolve(): ?PDO
    {
        // Já resolveu nesta request — retorna do cache
        if ($this->resolved) {
            return $this->cachedPdo;
        }

        $this->resolved = true;

        $userId = $this->getUserId();
        if ($userId === null) {
            return null;
        }

        try {
            $corePdo = PdoFactory::fromEnv('DB');
            $repo = new DatabaseConnectionRepository($corePdo);
            $activeConnection = $repo->findActiveByUser($userId);

            if ($activeConnection === null) {
                return null;
            }

            $service = new DatabaseConnectionService();
            $this->cachedPdo = $service->createPdoConnection([
                'service_uri'    => $activeConnection['service_uri'],
                'database_name'  => $activeConnection['database_name'],
                'host'           => $activeConnection['host'],
                'port'           => $activeConnection['port'],
                'username'       => $activeConnection['username'],
                'password'       => $repo->decryptPassword($activeConnection['password']),
                'driver'         => $activeConnection['driver'],
                'ssl_mode'       => $activeConnection['ssl_mode'],
                'ca_certificate' => $activeConnection['ca_certificate'],
                'persistent'     => true,
            ]);

            return $this->cachedPdo;
        } catch (\Throwable $e) {
            error_log("[DeveloperConnectionResolver] Erro ao resolver conexão personalizada: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve a conexão personalizada ou retorna a conexão padrão (core).
     */
    public function resolveOrDefault(): PDO
    {
        return $this->resolve() ?? PdoFactory::fromEnv('DB');
    }

    /**
     * Extrai o userId do token JWT.
     *
     * Fontes (em ordem de prioridade):
     *   1. Header Authorization: Bearer <token>
     *   2. Cookie auth_token
     *
     * O token é parseado para extrair o claim "sub" (subject = userId).
     * NÃO valida a assinatura aqui — a validação completa é feita pelo
     * AuthHybridMiddleware. Este método apenas extrai o identificador
     * para buscar a conexão personalizada.
     */
    public function getUserId(): ?string
    {
        if ($this->userIdResolved) {
            return $this->userId;
        }

        $this->userIdResolved = true;
        $this->userId = $this->extractUserIdFromToken();

        return $this->userId;
    }

    /**
     * Verifica se a request atual é para um módulo nativo do sistema.
     * Módulos nativos SEMPRE usam o banco core, nunca a conexão personalizada.
     */
    public function isNativeModuleRequest(): bool
    {
        static $nativePatterns = [
            '/api/auth',
            '/api/authenticador',
            '/api/usuario',
            '/api/documentacao',
            '/api/ide',
            '/api/system',
            '/dashboard',
            '/login',
            '/registro',
        ];

        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($nativePatterns as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se um módulo é nativo (pelo nome).
     */
    public function isNativeModule(string $moduleName): bool
    {
        static $nativeModules = [
            'Auth', 'Usuario', 'Authenticador',
            'Documentacao', 'IdeModuleBuilder', 'System',
        ];

        return in_array($moduleName, $nativeModules, true);
    }

    /**
     * Reseta o estado para a próxima request.
     * Chamado automaticamente em ambientes de teste ou long-running processes.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->cachedPdo = null;
            self::$instance->resolved = false;
            self::$instance->userId = null;
            self::$instance->userIdResolved = false;
        }
        self::$instance = null;
    }

    /**
     * Extrai o userId do JWT presente no header ou cookie.
     */
    private function extractUserIdFromToken(): ?string
    {
        $token = $this->extractToken();
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        // Decodifica o payload (parte 2 do JWT)
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload)) {
            return null;
        }

        $sub = $payload['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return null;
        }

        return $sub;
    }

    /**
     * Extrai o token JWT da request atual.
     * Prioridade: Authorization header > Cookie auth_token
     */
    private function extractToken(): string
    {
        // 1. Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader !== '' && preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            return $m[1];
        }

        // 2. Cookie auth_token
        $cookie = $_COOKIE['auth_token'] ?? '';
        if (is_string($cookie) && $cookie !== '') {
            return trim($cookie);
        }

        return '';
    }
}
