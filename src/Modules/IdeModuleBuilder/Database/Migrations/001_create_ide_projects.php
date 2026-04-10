<?php

use PDO;

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ide_projects (
                id UUID PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                module_name VARCHAR(100) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                files JSONB NOT NULL DEFAULT '{}',
                folders JSONB NOT NULL DEFAULT '[]',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ide_projects_user ON ide_projects(user_id)");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ide_projects_user_module ON ide_projects(user_id, module_name)");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ide_projects (
                id CHAR(36) PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                module_name VARCHAR(100) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                files JSON NOT NULL,
                folders JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ide_projects_user (user_id),
                UNIQUE INDEX idx_ide_projects_user_module (user_id, module_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS ide_projects");
    },
];
