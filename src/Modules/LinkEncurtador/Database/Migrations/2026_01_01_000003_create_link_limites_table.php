<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_limites (
                user_id    UUID        PRIMARY KEY,
                max_links  INTEGER     NOT NULL DEFAULT -1,
                criado_em  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            // -1 = ilimitado, 0 = bloqueado, N = limite
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_limites (
                user_id       CHAR(36)  PRIMARY KEY,
                max_links     INT       NOT NULL DEFAULT -1,
                criado_em     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS link_limites");
    },
];
