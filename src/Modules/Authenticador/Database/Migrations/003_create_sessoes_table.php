<?php
/**
 * Migration: Módulo Authenticador — Tabela sessoes
 * 
 * Armazena sessões de usuários autenticados
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sessoes (
                    id                  SERIAL          PRIMARY KEY,
                    uuid                UUID            NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                    usuario_uuid        UUID            NOT NULL,
                    token_hash          VARCHAR(255)    NULL,
                    refresh_token_hash  VARCHAR(255)    NULL,
                    ip_address          VARCHAR(45)     NULL,
                    user_agent          TEXT            NULL,
                    dispositivo_tipo    VARCHAR(50)     NULL,
                    dispositivo_nome    VARCHAR(255)    NULL,
                    navegador           VARCHAR(100)    NULL,
                    sistema_operacional VARCHAR(100)    NULL,
                    ativa               BOOLEAN         NOT NULL DEFAULT TRUE,
                    expira_em           TIMESTAMP       NOT NULL,
                    criada_em           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    ultimo_uso          TIMESTAMP       NULL,
                    revogada_em         TIMESTAMP       NULL,
                    revogada_por        UUID            NULL,
                    revogada_motivo     VARCHAR(255)    NULL
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessoes_usuario ON sessoes (usuario_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessoes_token ON sessoes (token_hash)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessoes_ativa ON sessoes (ativa)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessoes_expira ON sessoes (expira_em)");
            
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sessoes (
                    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                    uuid                CHAR(36)        NOT NULL UNIQUE,
                    usuario_uuid        CHAR(36)        NOT NULL,
                    token_hash          VARCHAR(255)    NULL,
                    refresh_token_hash  VARCHAR(255)    NULL,
                    ip_address          VARCHAR(45)     NULL,
                    user_agent          TEXT            NULL,
                    dispositivo_tipo    VARCHAR(50)     NULL,
                    dispositivo_nome    VARCHAR(255)    NULL,
                    navegador           VARCHAR(100)    NULL,
                    sistema_operacional VARCHAR(100)    NULL,
                    ativa               TINYINT(1)      NOT NULL DEFAULT 1,
                    expira_em           DATETIME        NOT NULL,
                    criada_em           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    ultimo_uso          DATETIME        NULL,
                    revogada_em         DATETIME        NULL,
                    revogada_por        CHAR(36)        NULL,
                    revogada_motivo     VARCHAR(255)    NULL,
                    INDEX idx_usuario (usuario_uuid),
                    INDEX idx_token (token_hash),
                    INDEX idx_ativa (ativa),
                    INDEX idx_expira (expira_em)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS sessoes");
    },
];
