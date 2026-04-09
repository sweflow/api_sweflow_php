<?php
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS avisos (
                    id          SERIAL       PRIMARY KEY,
                    titulo      VARCHAR(255) NOT NULL,
                    mensagem    TEXT         NOT NULL,
                    tipo        VARCHAR(20)  NOT NULL DEFAULT 'info'
                        CHECK (tipo IN ('info','sucesso','alerta','erro')),
                    ativo       BOOLEAN      NOT NULL DEFAULT TRUE,
                    criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP
                )
            ");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_avisos_ativo ON avisos (ativo)');
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS avisos (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    titulo      VARCHAR(255) NOT NULL,
                    mensagem    TEXT         NOT NULL,
                    tipo        VARCHAR(20)  NOT NULL DEFAULT 'info',
                    ativo       TINYINT(1)   NOT NULL DEFAULT 1,
                    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME,
                    INDEX idx_ativo (ativo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS avisos');
    },
];
