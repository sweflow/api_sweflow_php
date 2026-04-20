<?php

namespace Src\Modules\IdeModuleBuilder\Repositories;

use PDO;

class DatabaseConnectionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lista todas as conexões de um usuário
     */
    public function findByUser(string $usuarioUuid): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                connection_name,
                service_uri,
                database_name,
                host,
                port,
                username,
                driver,
                ssl_mode,
                is_active,
                created_at,
                updated_at
            FROM ide_database_connections
            WHERE usuario_uuid = ?
            ORDER BY is_active DESC, connection_name ASC
        ");
        
        $stmt->execute([$usuarioUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca a conexão ativa de um usuário
     */
    public function findActiveByUser(string $usuarioUuid): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                connection_name,
                service_uri,
                database_name,
                host,
                port,
                username,
                password,
                driver,
                ssl_mode,
                ca_certificate,
                is_active
            FROM ide_database_connections
            WHERE usuario_uuid = ? AND is_active = ?
            LIMIT 1
        ");
        
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isActive = $driver === 'pgsql' ? true : 1;
        
        $stmt->execute([$usuarioUuid, $isActive]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Busca uma conexão por ID (apenas do usuário)
     */
    public function findById(string $id, string $usuarioUuid): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ide_database_connections
            WHERE id = ? AND usuario_uuid = ?
        ");
        
        $stmt->execute([$id, $usuarioUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Cria uma nova conexão
     */
    public function create(array $data): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'pgsql' ? null : $this->generateUuid();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO ide_database_connections (
                " . ($driver === 'mysql' ? 'id,' : '') . "
                usuario_uuid,
                connection_name,
                service_uri,
                database_name,
                host,
                port,
                username,
                password,
                driver,
                ssl_mode,
                ca_certificate,
                is_active
            ) VALUES (
                " . ($driver === 'mysql' ? '?,' : '') . "
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
            " . ($driver === 'pgsql' ? 'RETURNING id' : '')
        );
        
        // Converte strings vazias para null de forma mais explícita
        $sslMode = (isset($data['ssl_mode']) && $data['ssl_mode'] !== '' && $data['ssl_mode'] !== null) 
            ? $data['ssl_mode'] 
            : null;
        $caCertificate = (isset($data['ca_certificate']) && $data['ca_certificate'] !== '' && $data['ca_certificate'] !== null) 
            ? $data['ca_certificate'] 
            : null;
        $serviceUri = (isset($data['service_uri']) && $data['service_uri'] !== '' && $data['service_uri'] !== null) 
            ? $data['service_uri'] 
            : null;
        
        $params = $driver === 'mysql' ? [$id] : [];
        $params = array_merge($params, [
            $data['usuario_uuid'],
            $data['connection_name'],
            $serviceUri,
            $data['database_name'],
            $data['host'],
            $data['port'] ?? ($data['driver'] === 'pgsql' ? 5432 : 3306),
            $data['username'],
            $this->encryptPassword($data['password']),
            $data['driver'] ?? 'pgsql',
            $sslMode,
            $caCertificate,
            // Para PostgreSQL, usa 'false' (string) ao invés de false (boolean)
            $driver === 'pgsql' ? 'false' : 0,
        ]);
        
        $stmt->execute($params);
        
        if ($driver === 'pgsql') {
            return $stmt->fetchColumn();
        }
        
        return $id;
    }

    /**
     * Atualiza uma conexão
     */
    public function update(string $id, string $usuarioUuid, array $data): bool
    {
        $fields = [];
        $params = [];
        
        if (isset($data['connection_name'])) {
            $fields[] = 'connection_name = ?';
            $params[] = $data['connection_name'];
        }
        
        if (isset($data['service_uri'])) {
            $fields[] = 'service_uri = ?';
            $params[] = $data['service_uri'];
        }
        
        if (isset($data['database_name'])) {
            $fields[] = 'database_name = ?';
            $params[] = $data['database_name'];
        }
        
        if (isset($data['host'])) {
            $fields[] = 'host = ?';
            $params[] = $data['host'];
        }
        
        if (isset($data['port'])) {
            $fields[] = 'port = ?';
            $params[] = $data['port'];
        }
        
        if (isset($data['username'])) {
            $fields[] = 'username = ?';
            $params[] = $data['username'];
        }
        
        if (isset($data['password'])) {
            $fields[] = 'password = ?';
            $params[] = $this->encryptPassword($data['password']);
        }
        
        if (isset($data['driver'])) {
            $fields[] = 'driver = ?';
            $params[] = $data['driver'];
        }
        
        if (isset($data['ssl_mode'])) {
            $fields[] = 'ssl_mode = ?';
            // Converte string vazia para null
            $params[] = !empty($data['ssl_mode']) ? $data['ssl_mode'] : null;
        }
        
        if (isset($data['ca_certificate'])) {
            $fields[] = 'ca_certificate = ?';
            // Converte string vazia para null
            $params[] = !empty($data['ca_certificate']) ? $data['ca_certificate'] : null;
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $fields[] = $driver === 'pgsql' ? 'updated_at = NOW()' : 'updated_at = CURRENT_TIMESTAMP';
        
        $params[] = $id;
        $params[] = $usuarioUuid;
        
        $sql = "UPDATE ide_database_connections 
                SET " . implode(', ', $fields) . "
                WHERE id = ? AND usuario_uuid = ?";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Ativa uma conexão (desativa as outras do usuário)
     */
    public function setActive(string $id, string $usuarioUuid): bool
    {
        $this->pdo->beginTransaction();
        
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $falseValue = $driver === 'pgsql' ? 'FALSE' : '0';
            $trueValue = $driver === 'pgsql' ? 'TRUE' : '1';
            
            // Desativa todas as conexões do usuário
            $stmt = $this->pdo->prepare("
                UPDATE ide_database_connections 
                SET is_active = $falseValue
                WHERE usuario_uuid = ?
            ");
            $stmt->execute([$usuarioUuid]);
            
            // Ativa a conexão específica
            $stmt = $this->pdo->prepare("
                UPDATE ide_database_connections 
                SET is_active = $trueValue
                WHERE id = ? AND usuario_uuid = ?
            ");
            $stmt->execute([$id, $usuarioUuid]);
            
            $this->pdo->commit();
            return true;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Desativa todas as conexões do usuário (volta para conexão padrão)
     */
    public function deactivateAll(string $usuarioUuid): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $falseValue = $driver === 'pgsql' ? 'FALSE' : '0';
        
        $stmt = $this->pdo->prepare("
            UPDATE ide_database_connections 
            SET is_active = $falseValue
            WHERE usuario_uuid = ?
        ");
        
        return $stmt->execute([$usuarioUuid]);
    }

    /**
     * Deleta uma conexão
     */
    public function delete(string $id, string $usuarioUuid): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM ide_database_connections 
            WHERE id = ? AND usuario_uuid = ?
        ");
        
        return $stmt->execute([$id, $usuarioUuid]);
    }

    /**
     * Criptografa a senha
     */
    private function encryptPassword(string $password): string
    {
        $key = $_ENV['APP_KEY'] ?? $_ENV['JWT_SECRET'] ?? 'default-key';
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Descriptografa a senha
     */
    public function decryptPassword(string $encryptedPassword): string
    {
        $key = $_ENV['APP_KEY'] ?? $_ENV['JWT_SECRET'] ?? 'default-key';
        $data = base64_decode($encryptedPassword);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Gera UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
