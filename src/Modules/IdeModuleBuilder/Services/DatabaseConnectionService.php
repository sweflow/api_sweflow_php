<?php

namespace Src\Modules\IdeModuleBuilder\Services;

use PDO;
use PDOException;

class DatabaseConnectionService
{
    /**
     * Testa uma conexão com o banco de dados
     */
    public function testConnection(array $config): array
    {
        try {
            // Para PostgreSQL gerenciado (como Aiven), o banco já existe
            // Então testamos conectando diretamente ao banco especificado
            $driver = $config['driver'] ?? 'pgsql';
            
            if ($driver === 'pgsql') {
                // Tenta conectar ao banco especificado
                $dsn = $this->buildDsn($config, true);
            } else {
                // MySQL: testa sem especificar banco
                $dsn = $this->buildDsn($config, false);
            }
            
            $options = $this->buildOptions($config);
            
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Testa a conexão
            $pdo->query('SELECT 1');
            
            return [
                'success' => true,
                'message' => 'Conexão estabelecida com sucesso!',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Falha na conexão: ' . $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Testa e cria o banco de dados se não existir
     */
    public function testAndCreateDatabase(array $config): array
    {
        try {
            $driver = $config['driver'] ?? 'pgsql';
            $databaseName = $config['database_name'];
            
            // Para PostgreSQL, tenta conectar diretamente ao banco especificado
            // (serviços gerenciados como Aiven já têm o banco criado)
            if ($driver === 'pgsql') {
                $dsn = $this->buildDsn($config, true);
                $options = $this->buildOptions($config);
                $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->query('SELECT 1');
                
                return [
                    'success' => true,
                    'message' => "Conexão estabelecida com sucesso ao banco '{$databaseName}'!",
                    'database_created' => false,
                ];
            }
            
            // Para MySQL, mantém lógica de criar banco se não existir
            // Conecta sem especificar database
            $dsn = $this->buildDsn($config, false);
            $options = $this->buildOptions($config);
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Verifica se o banco existe
            $stmt = $pdo->prepare("
                SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                WHERE SCHEMA_NAME = ?
            ");
            $stmt->execute([$databaseName]);
            $exists = $stmt->fetchColumn();
            
            if (!$exists) {
                // Cria o banco de dados
                $pdo->exec("CREATE DATABASE `" . $databaseName . "` 
                           CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $created = true;
            } else {
                $created = false;
            }
            
            // Testa conexão com o banco específico
            $dsnWithDb = $this->buildDsn($config, true);
            $pdoWithDb = new PDO($dsnWithDb, $config['username'], $config['password'], $options);
            $pdoWithDb->query('SELECT 1');
            
            return [
                'success' => true,
                'message' => $created 
                    ? "Banco de dados '{$databaseName}' criado e conexão estabelecida com sucesso!"
                    : "Conexão estabelecida com sucesso! O banco de dados '{$databaseName}' já existe.",
                'database_created' => $created,
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Erro ao criar/conectar ao banco de dados: ' . $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Cria uma conexão PDO a partir de uma configuração
     */
    public function createPdoConnection(array $config): PDO
    {
        $dsn = $this->buildDsn($config, true);
        $options = $this->buildOptions($config);
        
        $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        return $pdo;
    }

    /**
     * Constrói o DSN
     */
    private function buildDsn(array $config, bool $includeDatabase): string
    {
        // Se service_uri foi fornecido, usa ele
        if (!empty($config['service_uri'])) {
            return $config['service_uri'];
        }
        
        $driver = $config['driver'] ?? 'pgsql';
        $host = $config['host'];
        $port = $config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        
        $dsn = "{$driver}:host={$host};port={$port}";
        
        if ($includeDatabase) {
            $dsn .= ";dbname={$config['database_name']}";
        }
        
        // Adiciona SSL mode para PostgreSQL
        if ($driver === 'pgsql' && !empty($config['ssl_mode'])) {
            $dsn .= ";sslmode={$config['ssl_mode']}";
            
            // Se tem certificado CA, cria arquivo temporário e adiciona ao DSN
            if (!empty($config['ca_certificate'])) {
                $caCertPath = $this->createTempCertFile($config['ca_certificate']);
                if ($caCertPath) {
                    // Converte barras invertidas para barras normais (Windows)
                    $caCertPath = str_replace('\\', '/', $caCertPath);
                    $dsn .= ";sslrootcert={$caCertPath}";
                }
            }
        }
        
        return $dsn;
    }

    /**
     * Cria um arquivo temporário com o certificado CA
     */
    private function createTempCertFile(string $certContent): ?string
    {
        try {
            // Cria diretório temporário se não existir
            $tempDir = sys_get_temp_dir() . '/vupi_db_certs';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0700, true);
            }
            
            // Cria arquivo temporário com nome único
            $certFile = tempnam($tempDir, 'ca_cert_');
            if ($certFile === false) {
                return null;
            }
            
            // Escreve o certificado no arquivo
            file_put_contents($certFile, $certContent);
            
            // Registra para limpeza posterior
            register_shutdown_function(function() use ($certFile) {
                if (file_exists($certFile)) {
                    @unlink($certFile);
                }
            });
            
            return $certFile;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Constrói as opções do PDO
     */
    private function buildOptions(array $config): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        // Persistent connection (reutiliza conexão TCP entre requisições)
        if (!empty($config['persistent'])) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        
        $driver = $config['driver'] ?? 'pgsql';
        
        // Configurações SSL para MySQL
        if ($driver === 'mysql' && !empty($config['ca_certificate'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ca_certificate'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        
        return $options;
    }

    /**
     * Valida os dados de configuração
     */
    public function validateConfig(array $config): array
    {
        $errors = [];
        
        if (empty($config['connection_name'])) {
            $errors[] = 'Nome da conexão é obrigatório';
        }
        
        if (empty($config['database_name'])) {
            $errors[] = 'Nome do banco de dados é obrigatório';
        }
        
        if (empty($config['service_uri'])) {
            if (empty($config['host'])) {
                $errors[] = 'Host é obrigatório';
            }
            
            if (empty($config['port'])) {
                $errors[] = 'Porta é obrigatória';
            }
        }
        
        if (empty($config['username'])) {
            $errors[] = 'Usuário é obrigatório';
        }
        
        if (empty($config['password'])) {
            $errors[] = 'Senha é obrigatória';
        }
        
        $driver = $config['driver'] ?? 'pgsql';
        if (!in_array($driver, ['pgsql', 'mysql'])) {
            $errors[] = 'Driver deve ser pgsql ou mysql';
        }
        
        return $errors;
    }
}
