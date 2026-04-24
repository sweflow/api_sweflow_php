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
     *
     * Estratégias de resolução (em ordem):
     *   1. Token JWT (Bearer header ou cookie) → extrai userId → busca conexão ativa
     *   2. Fallback por módulo: extrai nome do módulo da URI → busca o dono em ide_projects
     *      → busca conexão ativa do dono. Cobre rotas públicas (registro, etc.)
     */
    public function resolve(): ?PDO
    {
        // Já resolveu nesta request — retorna do cache
        if ($this->resolved) {
            return $this->cachedPdo;
        }

        $this->resolved = true;

        // Estratégia 1: userId do token JWT
        $userId = $this->getUserId();

        // Estratégia 2: sem token → busca o dono do módulo pela URI
        if ($userId === null) {
            $userId = $this->resolveOwnerByUri();
            if ($userId !== null) {
                $this->log('fallback', "dono do módulo userId={$userId}");
            }
        }

        if ($userId === null) {
            $this->log('skip', 'sem token e sem módulo identificável');
            return null;
        }

        try {
            $corePdo = PdoFactory::fromEnv('DB');
            $repo = new DatabaseConnectionRepository($corePdo);
            $activeConnection = $repo->findActiveByUser($userId);

            if ($activeConnection === null) {
                $this->log('skip', "userId={$userId} sem conexão ativa");
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

            $this->log('ok', sprintf(
                "userId=%s conn=%s host=%s db=%s",
                $userId,
                $activeConnection['connection_name'] ?? '?',
                $activeConnection['host'] ?? '?',
                $activeConnection['database_name'] ?? '?'
            ));

            return $this->cachedPdo;
        } catch (\Throwable $e) {
            $this->log('error', "userId={$userId} erro={$e->getMessage()}");
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
            '/api/audit',
            '/api/status',
            '/api/db-status',
            '/api/env',
            '/api/capabilities',
            '/api/modules',
            '/api/dashboard',
            '/api/health',
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
     * Fallback: identifica o módulo pela URI e busca o userId do dono em ide_projects.
     *
     * Cobre rotas públicas (ex: /api/roxxer/registrar) onde não há token JWT.
     * Extrai o nome do módulo do segundo segmento da URI (/api/{modulo}/...).
     * Busca na tabela ide_projects qual usuário criou esse módulo.
     * Retorna o userId do dono para buscar sua conexão personalizada.
     */
    private function resolveOwnerByUri(): ?string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $segments = explode('/', trim($uri, '/'));

        // Espera formato: api/{moduleName}/...
        if (count($segments) < 2 || strtolower($segments[0]) !== 'api') {
            return null;
        }

        $moduleSlug = $segments[1]; // ex: "roxxer"

        // Ignora módulos nativos
        if ($this->isNativeModuleSlug($moduleSlug)) {
            return null;
        }

        try {
            $corePdo = PdoFactory::fromEnv('DB');

            // Busca o dono do módulo (case-insensitive) na tabela ide_projects
            $stmt = $corePdo->prepare("
                SELECT user_id 
                FROM ide_projects 
                WHERE LOWER(module_name) = LOWER(?) 
                LIMIT 1
            ");
            $stmt->execute([$moduleSlug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row['user_id'] ?? null;
        } catch (\Throwable $e) {
            $this->log('error', "resolveOwnerByUri falhou: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Verifica se um slug de URI corresponde a um módulo nativo.
     */
    private function isNativeModuleSlug(string $slug): bool
    {
        static $nativeSlugs = [
            'auth', 'authenticador', 'usuario', 'documentacao',
            'ide', 'system', 'dashboard', 'login', 'registro',
        ];

        return in_array(strtolower($slug), $nativeSlugs, true);
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

    /**
     * Loga informações de resolução de conexão.
     * Sempre vai para o error_log (arquivo de log do PHP-FPM em produção).
     */
    private function log(string $level, string $message): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        error_log("[DevConn:{$level}] {$message} uri={$uri}");
    }
}
