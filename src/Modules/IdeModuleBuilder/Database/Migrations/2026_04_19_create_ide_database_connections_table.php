<?php

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS database_connections (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    user_id VARCHAR(255) NOT NULL,
                    connection_name VARCHAR(100) NOT NULL,
                    service_uri TEXT,
                    database_name VARCHAR(100) NOT NULL,
                    host VARCHAR(255) NOT NULL,
                    port INTEGER NOT NULL DEFAULT 5432,
                    username VARCHAR(100) NOT NULL,
                    password TEXT NOT NULL,
                    driver VARCHAR(20) NOT NULL DEFAULT 'pgsql',
                    ssl_mode VARCHAR(20),
                    ca_certificate TEXT,
                    is_active BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMPTZ DEFAULT NOW(),
                    updated_at TIMESTAMPTZ DEFAULT NOW(),
                    UNIQUE(user_id, connection_name)
                )
            ");
            
            $pdo->exec("
                CREATE INDEX IF NOT EXISTS idx_db_conn_user 
                ON database_connections(user_id)
            ");
            
            $pdo->exec("
                CREATE INDEX IF NOT EXISTS idx_db_conn_active 
                ON database_connections(user_id, is_active) 
                WHERE is_active = TRUE
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS database_connections (
                    id CHAR(36) PRIMARY KEY,
                    user_id VARCHAR(255) NOT NULL,
                    connection_name VARCHAR(100) NOT NULL,
                    service_uri TEXT,
                    database_name VARCHAR(100) NOT NULL,
                    host VARCHAR(255) NOT NULL,
                    port INT NOT NULL DEFAULT 3306,
                    username VARCHAR(100) NOT NULL,
                    password TEXT NOT NULL,
                    driver VARCHAR(20) NOT NULL DEFAULT 'mysql',
                    ssl_mode VARCHAR(20),
                    ca_certificate TEXT,
                    is_active TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_connection (user_id, connection_name),
                    INDEX idx_db_conn_user (user_id),
                    INDEX idx_db_conn_active (user_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS database_connections");
    }
];
