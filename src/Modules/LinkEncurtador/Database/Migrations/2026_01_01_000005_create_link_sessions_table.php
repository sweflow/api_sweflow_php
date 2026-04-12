<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_sessoes (
                id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id    UUID        NOT NULL REFERENCES link_usuarios(id) ON DELETE CASCADE,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                criado_em  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_sessoes_token   ON link_sessoes (token_hash)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_sessoes_user    ON link_sessoes (user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_sessoes_expires ON link_sessoes (expires_at)");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_sessoes (
                id         CHAR(36)    PRIMARY KEY,
                user_id    CHAR(36)    NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME    NOT NULL,
                criado_em  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_link_sessoes_token   (token_hash),
                INDEX idx_link_sessoes_user    (user_id),
                INDEX idx_link_sessoes_expires (expires_at),
                FOREIGN KEY (user_id) REFERENCES link_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS link_sessoes");
    },
];
