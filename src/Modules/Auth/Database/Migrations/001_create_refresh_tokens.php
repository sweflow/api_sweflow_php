<?php
/**
 * Migration: Módulo Auth — Tabela refresh_tokens
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS refresh_tokens (
                    jti        UUID        NOT NULL PRIMARY KEY,
                    user_uuid  UUID        NOT NULL,
                    token_hash TEXT        NOT NULL,
                    expires_at TIMESTAMPTZ NOT NULL,
                    revoked    BOOLEAN     NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user     ON refresh_tokens (user_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_refresh_tokens_expires  ON refresh_tokens (expires_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_refresh_tokens_revoked  ON refresh_tokens (revoked)");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS refresh_tokens (
                    jti        CHAR(36)     NOT NULL PRIMARY KEY,
                    user_uuid  CHAR(36)     NOT NULL,
                    token_hash TEXT         NOT NULL,
                    expires_at DATETIME     NOT NULL,
                    revoked    TINYINT(1)   NOT NULL DEFAULT 0,
                    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_uuid),
                    INDEX idx_expires (expires_at),
                    INDEX idx_revoked (revoked)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS refresh_tokens");
    },
];
