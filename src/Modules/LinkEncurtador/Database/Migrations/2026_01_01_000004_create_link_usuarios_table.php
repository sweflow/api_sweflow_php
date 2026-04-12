<?php

use PDO;

/**
 * Tabela de usuários exclusiva do encurtador de links.
 * Completamente separada da tabela 'usuarios' do kernel.
 * Usuários do encurtador NÃO têm acesso à IDE ou ao dashboard da vupi.us API.
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_usuarios (
                id            UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
                nome          VARCHAR(120) NOT NULL DEFAULT '',
                email         VARCHAR(255) NOT NULL UNIQUE,
                senha_hash    VARCHAR(255) NOT NULL DEFAULT '',
                google_id     VARCHAR(128) NULL UNIQUE,
                avatar_url    TEXT         NOT NULL DEFAULT '',
                ativo         BOOLEAN      NOT NULL DEFAULT TRUE,
                criado_em     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                atualizado_em TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_usuarios_email     ON link_usuarios (email)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_usuarios_google_id ON link_usuarios (google_id)");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS link_usuarios (
                id            CHAR(36)     PRIMARY KEY,
                nome          VARCHAR(120) NOT NULL DEFAULT '',
                email         VARCHAR(255) NOT NULL UNIQUE,
                senha_hash    VARCHAR(255) NOT NULL DEFAULT '',
                google_id     VARCHAR(128) NULL UNIQUE,
                avatar_url    TEXT         NOT NULL DEFAULT '',
                ativo         TINYINT(1)   NOT NULL DEFAULT 1,
                criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_link_usuarios_email     (email),
                INDEX idx_link_usuarios_google_id (google_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS link_usuarios");
    },
];
