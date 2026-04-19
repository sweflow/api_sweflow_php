<?php
/**
 * Migration: Módulo Authenticador — Tabela auth_tokens
 * 
 * Armazena tokens de autenticação (JWT, refresh tokens, etc)
 * Permite revogação e gerenciamento de tokens
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS auth_tokens (
                    id                  SERIAL          PRIMARY KEY,
                    uuid                UUID            NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                    usuario_uuid        UUID            NOT NULL,
                    token_hash          VARCHAR(255)    NOT NULL UNIQUE,
                    token_type          VARCHAR(50)     NOT NULL DEFAULT 'access',
                    revoked             BOOLEAN         NOT NULL DEFAULT FALSE,
                    revoked_at          TIMESTAMP       NULL,
                    revoked_reason      VARCHAR(255)    NULL,
                    expires_at          TIMESTAMP       NOT NULL,
                    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at        TIMESTAMP       NULL,
                    ip_address          VARCHAR(45)     NULL,
                    user_agent          TEXT            NULL
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_tokens_usuario ON auth_tokens (usuario_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_tokens_hash ON auth_tokens (token_hash)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_tokens_expires ON auth_tokens (expires_at)");
            
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS auth_tokens (
                    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                    uuid                CHAR(36)        NOT NULL UNIQUE,
                    usuario_uuid        CHAR(36)        NOT NULL,
                    token_hash          VARCHAR(255)    NOT NULL UNIQUE,
                    token_type          VARCHAR(50)     NOT NULL DEFAULT 'access',
                    revoked             TINYINT(1)      NOT NULL DEFAULT 0,
                    revoked_at          DATETIME        NULL,
                    revoked_reason      VARCHAR(255)    NULL,
                    expires_at          DATETIME        NOT NULL,
                    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at        DATETIME        NULL,
                    ip_address          VARCHAR(45)     NULL,
                    user_agent          TEXT            NULL,
                    INDEX idx_usuario (usuario_uuid),
                    INDEX idx_hash (token_hash),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS auth_tokens");
    },
];
