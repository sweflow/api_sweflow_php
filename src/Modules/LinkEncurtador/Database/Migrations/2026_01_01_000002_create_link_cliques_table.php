<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_cliques (
                id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                link_id    UUID        NOT NULL REFERENCES links(id) ON DELETE CASCADE,
                ip         VARCHAR(45) NOT NULL DEFAULT '',
                referrer   TEXT        NOT NULL DEFAULT '',
                user_agent TEXT        NOT NULL DEFAULT '',
                clicado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_cliques_link_id ON link_cliques (link_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_cliques_data    ON link_cliques (clicado_em)");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_cliques (
                id         CHAR(36)    PRIMARY KEY,
                link_id    CHAR(36)    NOT NULL,
                ip         VARCHAR(45) NOT NULL DEFAULT '',
                referrer   TEXT        NOT NULL DEFAULT '',
                user_agent TEXT        NOT NULL DEFAULT '',
                clicado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_link_cliques_link_id (link_id),
                INDEX idx_link_cliques_data    (clicado_em),
                FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS link_cliques");
    },
];
