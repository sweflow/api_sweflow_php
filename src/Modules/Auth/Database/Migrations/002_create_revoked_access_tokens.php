<?php
/**
 * Migration: Módulo Auth — Tabela revoked_access_tokens (blacklist JWT)
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS revoked_access_tokens (
                    jti        UUID        NOT NULL PRIMARY KEY,
                    user_uuid  UUID        NOT NULL,
                    expires_at TIMESTAMPTZ NOT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revoked_access_user    ON revoked_access_tokens (user_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revoked_access_expires ON revoked_access_tokens (expires_at)");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS revoked_access_tokens (
                    jti        CHAR(36)  NOT NULL PRIMARY KEY,
                    user_uuid  CHAR(36)  NOT NULL,
                    expires_at DATETIME  NOT NULL,
                    created_at DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_uuid),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS revoked_access_tokens");
    },
];
