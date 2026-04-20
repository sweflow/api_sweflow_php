<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS estoque (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    produto VARCHAR(255) NOT NULL,
                    quantidade NUMERIC(12,2) NOT NULL DEFAULT 0,
                    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMPTZ NULL
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS estoque (
                    id CHAR(36) PRIMARY KEY,
                    produto VARCHAR(255) NOT NULL,
                    quantidade DECIMAL(12,2) NOT NULL DEFAULT 0,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS estoque");
    },
];