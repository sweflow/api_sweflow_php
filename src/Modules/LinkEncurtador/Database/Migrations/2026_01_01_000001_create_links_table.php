<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS links (
                id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id     UUID         NOT NULL,
                alias       VARCHAR(32)  NOT NULL UNIQUE,
                url         TEXT         NOT NULL,
                titulo      VARCHAR(255) NOT NULL DEFAULT '',
                cliques     INTEGER      NOT NULL DEFAULT 0,
                ativo       BOOLEAN      NOT NULL DEFAULT TRUE,
                expires_at  TIMESTAMPTZ  NULL,
                criado_em   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_user_id  ON links (user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_alias    ON links (alias)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_expires  ON links (expires_at)");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS links (
                id            CHAR(36)     PRIMARY KEY,
                user_id       CHAR(36)     NOT NULL,
                alias         VARCHAR(32)  NOT NULL UNIQUE,
                url           TEXT         NOT NULL,
                titulo        VARCHAR(255) NOT NULL DEFAULT '',
                cliques       INT          NOT NULL DEFAULT 0,
                ativo         TINYINT(1)   NOT NULL DEFAULT 1,
                expires_at    DATETIME     NULL,
                criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_links_user_id (user_id),
                INDEX idx_links_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS links");
    },
];
