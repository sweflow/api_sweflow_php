<?php
/**
 * Migration: Adiciona coluna senha_alterada_em à tabela usuarios.
 * Usada para invalidar tokens emitidos antes de uma troca de senha.
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS senha_alterada_em TIMESTAMP NULL");
        } else {
            // MySQL: verifica se a coluna já existe antes de adicionar
            $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'senha_alterada_em'");
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN senha_alterada_em DATETIME NULL");
            }
        }
    },
    'down' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE usuarios DROP COLUMN IF EXISTS senha_alterada_em");
        } else {
            $pdo->exec("ALTER TABLE usuarios DROP COLUMN senha_alterada_em");
        }
    },
];
