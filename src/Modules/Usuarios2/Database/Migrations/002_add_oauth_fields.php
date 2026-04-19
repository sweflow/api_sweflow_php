<?php

/**
 * Migration: Adiciona campos OAuth2 à tabela usuarios2
 */

return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                ALTER TABLE usuarios2
                ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL,
                ADD COLUMN IF NOT EXISTS facebook_id VARCHAR(255) NULL,
                ADD COLUMN IF NOT EXISTS github_id VARCHAR(255) NULL,
                ADD COLUMN IF NOT EXISTS oauth_provider VARCHAR(50) NULL,
                ADD COLUMN IF NOT EXISTS oauth_avatar TEXT NULL;
                
                CREATE INDEX IF NOT EXISTS idx_usuarios2_google_id ON usuarios2(google_id);
                CREATE INDEX IF NOT EXISTS idx_usuarios2_facebook_id ON usuarios2(facebook_id);
                CREATE INDEX IF NOT EXISTS idx_usuarios2_github_id ON usuarios2(github_id);
            ");
        } else {
            // MySQL
            $pdo->exec("
                ALTER TABLE usuarios2
                ADD COLUMN google_id VARCHAR(255) NULL AFTER email_verificado,
                ADD COLUMN facebook_id VARCHAR(255) NULL AFTER google_id,
                ADD COLUMN github_id VARCHAR(255) NULL AFTER facebook_id,
                ADD COLUMN oauth_provider VARCHAR(50) NULL AFTER github_id,
                ADD COLUMN oauth_avatar TEXT NULL AFTER oauth_provider;
                
                ALTER TABLE usuarios2
                ADD INDEX idx_usuarios2_google_id (google_id),
                ADD INDEX idx_usuarios2_facebook_id (facebook_id),
                ADD INDEX idx_usuarios2_github_id (github_id);
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $pdo->exec("
                DROP INDEX IF EXISTS idx_usuarios2_google_id;
                DROP INDEX IF EXISTS idx_usuarios2_facebook_id;
                DROP INDEX IF EXISTS idx_usuarios2_github_id;
                
                ALTER TABLE usuarios2
                DROP COLUMN IF EXISTS google_id,
                DROP COLUMN IF EXISTS facebook_id,
                DROP COLUMN IF EXISTS github_id,
                DROP COLUMN IF EXISTS oauth_provider,
                DROP COLUMN IF EXISTS oauth_avatar;
            ");
        } else {
            // MySQL
            $pdo->exec("
                ALTER TABLE usuarios2
                DROP INDEX idx_usuarios2_google_id,
                DROP INDEX idx_usuarios2_facebook_id,
                DROP INDEX idx_usuarios2_github_id;
                
                ALTER TABLE usuarios2
                DROP COLUMN google_id,
                DROP COLUMN facebook_id,
                DROP COLUMN github_id,
                DROP COLUMN oauth_provider,
                DROP COLUMN oauth_avatar;
            ");
        }
    }
];
