<?php
/**
 * Migration: Módulo Authenticador — Tabela rate_limits
 * 
 * Armazena tentativas de requisições para rate limiting
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id              SERIAL          PRIMARY KEY,
                    identifier      VARCHAR(255)    NOT NULL,
                    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_identifier ON rate_limits (identifier)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_created_at ON rate_limits (created_at)");
            
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                    identifier      VARCHAR(255)    NOT NULL,
                    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_identifier (identifier),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS rate_limits");
    },
];
