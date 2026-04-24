<?php

/**
 * Migration de correção: renomeia a tabela database_connections → ide_database_connections
 * e a coluna user_id → usuario_uuid, caso a migration original tenha criado com nomes errados.
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Verifica se a tabela antiga existe
        $oldTableExists = false;
        $newTableExists = false;

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT to_regclass('public.database_connections') IS NOT NULL AS exists");
            $stmt->execute();
            $oldTableExists = (bool) $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT to_regclass('public.ide_database_connections') IS NOT NULL AS exists");
            $stmt->execute();
            $newTableExists = (bool) $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'database_connections'");
            $stmt->execute();
            $oldTableExists = (bool) $stmt->fetch();

            $stmt = $pdo->prepare("SHOW TABLES LIKE 'ide_database_connections'");
            $stmt->execute();
            $newTableExists = (bool) $stmt->fetch();
        }

        // Se a tabela nova já existe, nada a fazer
        if ($newTableExists) {
            // Garante que a coluna usuario_uuid existe (pode ter sido criada com user_id)
            if ($driver === 'pgsql') {
                $stmt = $pdo->prepare("
                    SELECT column_name FROM information_schema.columns 
                    WHERE table_name = 'ide_database_connections' AND column_name = 'user_id'
                ");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $pdo->exec("ALTER TABLE ide_database_connections RENAME COLUMN user_id TO usuario_uuid");
                }
            } else {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM ide_database_connections LIKE 'user_id'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $pdo->exec("ALTER TABLE ide_database_connections CHANGE user_id usuario_uuid VARCHAR(255) NOT NULL");
                }
            }
            return;
        }

        // Se a tabela antiga existe, renomeia e corrige a coluna
        if ($oldTableExists) {
            if ($driver === 'pgsql') {
                $pdo->exec("ALTER TABLE database_connections RENAME TO ide_database_connections");
                $pdo->exec("ALTER TABLE ide_database_connections RENAME COLUMN user_id TO usuario_uuid");

                // Recria os índices com os nomes corretos
                $pdo->exec("DROP INDEX IF EXISTS idx_db_conn_user");
                $pdo->exec("DROP INDEX IF EXISTS idx_db_conn_active");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_db_conn_user ON ide_database_connections(usuario_uuid)");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_db_conn_active ON ide_database_connections(usuario_uuid, is_active) WHERE is_active = TRUE");

                // Atualiza a constraint UNIQUE
                $pdo->exec("ALTER TABLE ide_database_connections DROP CONSTRAINT IF EXISTS database_connections_user_id_connection_name_key");
                $pdo->exec("ALTER TABLE ide_database_connections ADD CONSTRAINT ide_database_connections_usuario_uuid_connection_name_key UNIQUE (usuario_uuid, connection_name)");
            } else {
                $pdo->exec("RENAME TABLE database_connections TO ide_database_connections");
                $pdo->exec("ALTER TABLE ide_database_connections CHANGE user_id usuario_uuid VARCHAR(255) NOT NULL");

                // Recria os índices
                try { $pdo->exec("DROP INDEX idx_db_conn_user ON ide_database_connections"); } catch (\Exception $e) {}
                try { $pdo->exec("DROP INDEX idx_db_conn_active ON ide_database_connections"); } catch (\Exception $e) {}
                try { $pdo->exec("DROP INDEX unique_user_connection ON ide_database_connections"); } catch (\Exception $e) {}

                $pdo->exec("CREATE INDEX idx_db_conn_user ON ide_database_connections(usuario_uuid)");
                $pdo->exec("CREATE INDEX idx_db_conn_active ON ide_database_connections(usuario_uuid, is_active)");
                $pdo->exec("ALTER TABLE ide_database_connections ADD UNIQUE KEY unique_user_connection (usuario_uuid, connection_name)");
            }
            return;
        }

        // Se nenhuma das duas tabelas existe, a migration principal vai criar corretamente
    },

    'down' => function (PDO $pdo): void {
        // Reversão: renomeia de volta (apenas se necessário)
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT to_regclass('public.ide_database_connections') IS NOT NULL AS exists");
            $stmt->execute();
            if ($stmt->fetchColumn()) {
                $pdo->exec("ALTER TABLE ide_database_connections RENAME COLUMN usuario_uuid TO user_id");
                $pdo->exec("ALTER TABLE ide_database_connections RENAME TO database_connections");
            }
        } else {
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'ide_database_connections'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $pdo->exec("ALTER TABLE ide_database_connections CHANGE usuario_uuid user_id VARCHAR(255) NOT NULL");
                $pdo->exec("RENAME TABLE ide_database_connections TO database_connections");
            }
        }
    }
];
