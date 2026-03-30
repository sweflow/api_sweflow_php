<?php
/**
 * Migration: Módulo Usuario — Tabela usuarios
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios (
                    uuid                    UUID         NOT NULL PRIMARY KEY,
                    nome_completo           VARCHAR(255) NOT NULL,
                    username                VARCHAR(50)  NOT NULL UNIQUE,
                    email                   VARCHAR(255) NOT NULL UNIQUE,
                    senha_hash              VARCHAR(255) NOT NULL,
                    url_avatar              VARCHAR(255),
                    url_capa                VARCHAR(255),
                    biografia               TEXT,
                    nivel_acesso            VARCHAR(20)  DEFAULT 'usuario'
                        CHECK (nivel_acesso IN ('usuario','admin','moderador','admin_system')),
                    token_recuperacao_senha VARCHAR(255),
                    token_verificacao_email VARCHAR(255),
                    ativo                   BOOLEAN      NOT NULL DEFAULT TRUE,
                    verificado_email        BOOLEAN      NOT NULL DEFAULT FALSE,
                    criado_em               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em           TIMESTAMP,
                    status_verificacao      VARCHAR(30)  DEFAULT 'Não verificado'
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_email    ON usuarios (email)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_username ON usuarios (username)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_ativo    ON usuarios (ativo)");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios (
                    uuid                    CHAR(36)     NOT NULL PRIMARY KEY,
                    nome_completo           VARCHAR(255) NOT NULL,
                    username                VARCHAR(50)  NOT NULL UNIQUE,
                    email                   VARCHAR(255) NOT NULL UNIQUE,
                    senha_hash              VARCHAR(255) NOT NULL,
                    url_avatar              VARCHAR(255),
                    url_capa                VARCHAR(255),
                    biografia               TEXT,
                    nivel_acesso            VARCHAR(20)  DEFAULT 'usuario',
                    token_recuperacao_senha VARCHAR(255),
                    token_verificacao_email VARCHAR(255),
                    ativo                   TINYINT(1)   NOT NULL DEFAULT 1,
                    verificado_email        TINYINT(1)   NOT NULL DEFAULT 0,
                    criado_em               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em           DATETIME,
                    status_verificacao      VARCHAR(30)  DEFAULT 'Não verificado',
                    INDEX idx_email (email),
                    INDEX idx_username (username),
                    INDEX idx_ativo (ativo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS usuarios");
    },
];
