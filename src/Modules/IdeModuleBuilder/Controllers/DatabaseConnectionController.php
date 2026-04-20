<?php

namespace Src\Modules\IdeModuleBuilder\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\IdeModuleBuilder\Repositories\DatabaseConnectionRepository;
use Src\Modules\IdeModuleBuilder\Services\DatabaseConnectionService;

class DatabaseConnectionController
{
    private DatabaseConnectionRepository $repository;
    private DatabaseConnectionService $service;

    public function __construct(
        DatabaseConnectionRepository $repository,
        DatabaseConnectionService $service
    ) {
        $this->repository = $repository;
        $this->service = $service;
    }

    /**
     * Lista todas as conexões do usuário
     * GET /api/ide/database-connections
     */
    public function index(Request $request): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        $connections = $this->repository->findByUser($usuarioUuid);
        
        return Response::json([
            'connections' => $connections,
        ]);
    }

    /**
     * Testa uma conexão
     * POST /api/ide/database-connections/test
     */
    public function test(Request $request): Response
    {
        // Sanitiza os dados de entrada
        $data = $this->sanitizeConnectionData($request->body);
        
        // Valida os dados
        $errors = $this->service->validateConfig($data);
        if (!empty($errors)) {
            return Response::json([
                'success' => false,
                'errors' => $errors,
            ], 400);
        }
        
        // Testa a conexão
        $result = $this->service->testConnection($data);
        
        return Response::json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Sanitiza os dados de entrada da conexão de banco de dados
     */
    private function sanitizeConnectionData(array $data): array
    {
        $sanitized = [];
        
        // connection_name: apenas alfanuméricos, espaços, hífens e underscores
        if (isset($data['connection_name'])) {
            $sanitized['connection_name'] = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', trim($data['connection_name']));
            $sanitized['connection_name'] = substr($sanitized['connection_name'], 0, 255);
        }
        
        // service_uri: valida formato de URI (opcional)
        if (isset($data['service_uri']) && !empty($data['service_uri'])) {
            $uri = filter_var(trim($data['service_uri']), FILTER_SANITIZE_URL);
            // Valida que é uma URI válida
            if (filter_var($uri, FILTER_VALIDATE_URL) !== false || preg_match('/^(mysql|pgsql|postgres):\/\//', $uri)) {
                $sanitized['service_uri'] = $uri;
            }
        }
        
        // database_name: apenas alfanuméricos, underscores e hífens
        if (isset($data['database_name'])) {
            $sanitized['database_name'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($data['database_name']));
            $sanitized['database_name'] = substr($sanitized['database_name'], 0, 255);
        }
        
        // host: valida hostname ou IP
        if (isset($data['host'])) {
            $host = trim($data['host']);
            // Remove caracteres perigosos mas mantém pontos, hífens e alfanuméricos
            $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);
            $sanitized['host'] = substr($host, 0, 255);
        }
        
        // port: apenas números, entre 1 e 65535
        if (isset($data['port'])) {
            $port = filter_var($data['port'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535]
            ]);
            if ($port !== false) {
                $sanitized['port'] = $port;
            }
        }
        
        // username: remove caracteres especiais perigosos
        if (isset($data['username'])) {
            $sanitized['username'] = preg_replace('/[^a-zA-Z0-9_\-@.]/', '', trim($data['username']));
            $sanitized['username'] = substr($sanitized['username'], 0, 255);
        }
        
        // password: mantém como está (será criptografada), mas limita tamanho
        if (isset($data['password'])) {
            $sanitized['password'] = substr($data['password'], 0, 1000);
        }
        
        // driver: apenas valores permitidos
        if (isset($data['driver'])) {
            $driver = strtolower(trim($data['driver']));
            if (in_array($driver, ['pgsql', 'mysql'], true)) {
                $sanitized['driver'] = $driver;
            }
        }
        
        // ssl_mode: apenas valores permitidos
        if (isset($data['ssl_mode']) && !empty($data['ssl_mode'])) {
            $sslMode = strtolower(trim($data['ssl_mode']));
            if (in_array($sslMode, ['require', 'prefer', 'allow', 'disable'], true)) {
                $sanitized['ssl_mode'] = $sslMode;
            }
        }
        
        // ca_certificate: valida formato de certificado PEM
        if (isset($data['ca_certificate']) && !empty($data['ca_certificate'])) {
            $cert = trim($data['ca_certificate']);
            // Verifica se parece com um certificado PEM válido
            if (preg_match('/^-----BEGIN CERTIFICATE-----[\s\S]+-----END CERTIFICATE-----\s*$/', $cert)) {
                $sanitized['ca_certificate'] = $cert;
            }
        }
        
        // usuario_uuid: valida formato UUID
        if (isset($data['usuario_uuid'])) {
            $uuid = trim($data['usuario_uuid']);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                $sanitized['usuario_uuid'] = $uuid;
            }
        }
        
        return $sanitized;
    }

    /**
     * Cria uma nova conexão
     * POST /api/ide/database-connections
     */
    public function store(Request $request): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        // Sanitiza os dados de entrada
        $data = $this->sanitizeConnectionData($request->body);
        $data['usuario_uuid'] = $usuarioUuid;
        
        // Remove campos vazios que devem ser null
        foreach (['ssl_mode', 'ca_certificate', 'service_uri'] as $field) {
            if (isset($data[$field]) && $data[$field] === '') {
                unset($data[$field]);
            }
        }
        
        // Valida os dados
        $errors = $this->service->validateConfig($data);
        if (!empty($errors)) {
            return Response::json([
                'success' => false,
                'errors' => $errors,
            ], 400);
        }
        
        try {
            // Testa e cria o banco de dados
            $testResult = $this->service->testAndCreateDatabase($data);
            
            if (!$testResult['success']) {
                return Response::json($testResult, 400);
            }
            
            // Salva a conexão
            $connectionId = $this->repository->create($data);
            
            // Busca a conexão criada para retornar
            $connection = $this->repository->findById($connectionId, $usuarioUuid);
            
            return Response::json([
                'success' => true,
                'message' => 'Conexão criada com sucesso!',
                'connection' => $connection,
                'database_created' => $testResult['database_created'] ?? false,
            ], 201);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'error' => 'Erro ao criar conexão: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Atualiza uma conexão
     * PUT /api/ide/database-connections/{id}
     */
    public function update(Request $request, string $id): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        // Verifica se a conexão existe e pertence ao usuário
        $connection = $this->repository->findById($id, $usuarioUuid);
        if (!$connection) {
            return Response::json(['error' => 'Conexão não encontrada'], 404);
        }
        
        // Sanitiza os dados de entrada
        $data = $this->sanitizeConnectionData($request->body);
        
        try {
            // Se mudou credenciais, testa a conexão
            if (isset($data['host']) || isset($data['port']) || 
                isset($data['username']) || isset($data['password'])) {
                $testConfig = array_merge($connection, $data);
                
                // Descriptografa a senha antiga se não foi fornecida nova
                if (!isset($data['password'])) {
                    $testConfig['password'] = $this->repository->decryptPassword($connection['password']);
                }
                
                $testResult = $this->service->testConnection($testConfig);
                
                if (!$testResult['success']) {
                    return Response::json($testResult, 400);
                }
            }
            
            // Atualiza a conexão
            $this->repository->update($id, $usuarioUuid, $data);
            
            return Response::json([
                'success' => true,
                'message' => 'Conexão atualizada com sucesso!',
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'error' => 'Erro ao atualizar conexão: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ativa uma conexão
     * POST /api/ide/database-connections/{id}/activate
     */
    public function activate(Request $request, string $id): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        // Verifica se a conexão existe e pertence ao usuário
        $connection = $this->repository->findById($id, $usuarioUuid);
        if (!$connection) {
            return Response::json(['error' => 'Conexão não encontrada'], 404);
        }
        
        try {
            $this->repository->setActive($id, $usuarioUuid);
            
            return Response::json([
                'success' => true,
                'message' => 'Conexão ativada com sucesso!',
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'error' => 'Erro ao ativar conexão: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Desativa todas as conexões (volta para padrão)
     * POST /api/ide/database-connections/deactivate-all
     */
    public function deactivateAll(Request $request): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        try {
            $this->repository->deactivateAll($usuarioUuid);
            
            return Response::json([
                'success' => true,
                'message' => 'Todas as conexões foram desativadas. Usando conexão padrão.',
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'error' => 'Erro ao desativar conexões: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deleta uma conexão
     * DELETE /api/ide/database-connections/{id}
     */
    public function destroy(Request $request, string $id): Response
    {
        // Pega o usuário do atributo injetado pelo AuthHybridMiddleware
        $authUser = $request->attribute('auth_user');
        
        if (!$authUser) {
            return Response::json(['error' => 'Usuário não autenticado'], 401);
        }
        
        $usuarioUuid = $authUser->getUuid()->toString();
        
        // Verifica se a conexão existe e pertence ao usuário
        $connection = $this->repository->findById($id, $usuarioUuid);
        if (!$connection) {
            return Response::json(['error' => 'Conexão não encontrada'], 404);
        }
        
        try {
            $this->repository->delete($id, $usuarioUuid);
            
            return Response::json([
                'success' => true,
                'message' => 'Conexão deletada com sucesso!',
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'error' => 'Erro ao deletar conexão: ' . $e->getMessage(),
            ], 500);
        }
    }
}
