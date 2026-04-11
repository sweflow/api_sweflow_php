<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tarefas (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                titulo VARCHAR(255) NOT NULL,
                concluida BOOLEAN NOT NULL DEFAULT FALSE,
                user_id VARCHAR(255) NOT NULL,
                criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tarefas (
                id CHAR(36) PRIMARY KEY,
                titulo VARCHAR(255) NOT NULL,
                concluida TINYINT(1) NOT NULL DEFAULT 0,
                user_id VARCHAR(255) NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS tarefas");
    },
];