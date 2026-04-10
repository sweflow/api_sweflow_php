<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ide_user_limits (
                user_id VARCHAR(255) PRIMARY KEY,
                max_projects INT NOT NULL DEFAULT -1,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ide_user_limits (
                user_id VARCHAR(255) PRIMARY KEY,
                max_projects INT NOT NULL DEFAULT -1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS ide_user_limits");
    },
];
