<?php
/**
 * Migration: Módulo Authenticador — Tabela historico_logins
 * 
 * Armazena histórico de tentativas de login (sucesso e falha)
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS historico_logins (
                    id                  SERIAL          PRIMARY KEY,
                    uuid                UUID            NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                    usuario_uuid        UUID            NULL,
                    email_tentativa     VARCHAR(255)    NULL,
                    username_tentativa  VARCHAR(100)    NULL,
                    sucesso             BOOLEAN         NOT NULL DEFAULT FALSE,
                    motivo_falha        VARCHAR(255)    NULL,
                    ip_address          VARCHAR(45)     NULL,
                    user_agent          TEXT            NULL,
                    dispositivo_tipo    VARCHAR(50)     NULL,
                    dispositivo_nome    VARCHAR(255)    NULL,
                    navegador           VARCHAR(100)    NULL,
                    sistema_operacional VARCHAR(100)    NULL,
                    mfa_usado           BOOLEAN         NOT NULL DEFAULT FALSE,
                    mfa_sucesso         BOOLEAN         NULL,
                    criado_em           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historico_logins_usuario ON historico_logins (usuario_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historico_logins_sucesso ON historico_logins (sucesso)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historico_logins_criado ON historico_logins (criado_em)");
            
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS historico_logins (
                    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                    uuid                CHAR(36)        NOT NULL UNIQUE,
                    usuario_uuid        CHAR(36)        NULL,
                    email_tentativa     VARCHAR(255)    NULL,
                    username_tentativa  VARCHAR(100)    NULL,
                    sucesso             TINYINT(1)      NOT NULL DEFAULT 0,
                    motivo_falha        VARCHAR(255)    NULL,
                    ip_address          VARCHAR(45)     NULL,
                    user_agent          TEXT            NULL,
                    dispositivo_tipo    VARCHAR(50)     NULL,
                    dispositivo_nome    VARCHAR(255)    NULL,
                    navegador           VARCHAR(100)    NULL,
                    sistema_operacional VARCHAR(100)    NULL,
                    mfa_usado           TINYINT(1)      NOT NULL DEFAULT 0,
                    mfa_sucesso         TINYINT(1)      NULL,
                    criado_em           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_usuario (usuario_uuid),
                    INDEX idx_sucesso (sucesso),
                    INDEX idx_criado (criado_em)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS historico_logins");
    },
];
