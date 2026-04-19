<?php
/**
 * Migration: Módulo Authenticador — Sistema de Permissões (RBAC)
 * 
 * Implementa Role-Based Access Control (RBAC) com:
 * - Roles (papéis/funções)
 * - Permissions (permissões específicas)
 * - Relacionamento many-to-many
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            // ═══════════════════════════════════════════════════════════
            // Tabela: roles (papéis/funções)
            // ═══════════════════════════════════════════════════════════
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id              SERIAL          PRIMARY KEY,
                    uuid            UUID            NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                    nome            VARCHAR(50)     NOT NULL UNIQUE,
                    slug            VARCHAR(50)     NOT NULL UNIQUE,
                    descricao       TEXT            NULL,
                    nivel           INTEGER         NOT NULL DEFAULT 0, -- hierarquia (maior = mais poder)
                    ativo           BOOLEAN         NOT NULL DEFAULT TRUE,
                    sistema         BOOLEAN         NOT NULL DEFAULT FALSE, -- role do sistema (não pode deletar)
                    criado_em       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em   TIMESTAMP       NULL
                )
            ");
            
            // ═══════════════════════════════════════════════════════════
            // Tabela: permissions (permissões)
            // ═══════════════════════════════════════════════════════════
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS permissions (
                    id              SERIAL          PRIMARY KEY,
                    uuid            UUID            NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                    nome            VARCHAR(100)    NOT NULL UNIQUE,
                    slug            VARCHAR(100)    NOT NULL UNIQUE,
                    descricao       TEXT            NULL,
                    modulo          VARCHAR(50)     NULL, -- módulo relacionado
                    categoria       VARCHAR(50)     NULL, -- categoria da permissão
                    ativo           BOOLEAN         NOT NULL DEFAULT TRUE,
                    criado_em       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em   TIMESTAMP       NULL
                )
            ");
            
            // ═══════════════════════════════════════════════════════════
            // Tabela: role_permissions (many-to-many)
            // ═══════════════════════════════════════════════════════════
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS role_permissions (
                    role_id         INTEGER         NOT NULL,
                    permission_id   INTEGER         NOT NULL,
                    concedido_em    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    concedido_por   UUID            NULL,
                    
                    PRIMARY KEY (role_id, permission_id),
                    
                    CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id) 
                        REFERENCES roles(id) ON DELETE CASCADE,
                    CONSTRAINT fk_role_perm_permission FOREIGN KEY (permission_id) 
                        REFERENCES permissions(id) ON DELETE CASCADE
                )
            ");
            
            // ═══════════════════════════════════════════════════════════
            // Tabela: usuario_roles (usuários x roles)
            // ═══════════════════════════════════════════════════════════
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuario_roles (
                    usuario_uuid    UUID            NOT NULL,
                    role_id         INTEGER         NOT NULL,
                    atribuido_em    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atribuido_por   UUID            NULL,
                    expira_em       TIMESTAMP       NULL, -- role temporária
                    
                    PRIMARY KEY (usuario_uuid, role_id),
                    
                    CONSTRAINT fk_user_role_role FOREIGN KEY (role_id) 
                        REFERENCES roles(id) ON DELETE CASCADE
                )
            ");
            
            // ═══════════════════════════════════════════════════════════
            // Tabela: usuario_permissions (permissões diretas)
            // ═══════════════════════════════════════════════════════════
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuario_permissions (
                    usuario_uuid    UUID            NOT NULL,
                    permission_id   INTEGER         NOT NULL,
                    tipo            VARCHAR(10)     NOT NULL DEFAULT 'grant', -- grant ou deny
                    concedido_em    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    concedido_por   UUID            NULL,
                    expira_em       TIMESTAMP       NULL,
                    
                    PRIMARY KEY (usuario_uuid, permission_id),
                    
                    CONSTRAINT fk_user_perm_permission FOREIGN KEY (permission_id) 
                        REFERENCES permissions(id) ON DELETE CASCADE,
                    CONSTRAINT chk_tipo CHECK (tipo IN ('grant', 'deny'))
                )
            ");
            
            // Índices
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_roles_slug ON roles (slug)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_roles_nivel ON roles (nivel)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_permissions_slug ON permissions (slug)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_permissions_modulo ON permissions (modulo)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuario_roles_usuario ON usuario_roles (usuario_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuario_permissions_usuario ON usuario_permissions (usuario_uuid)");
            
        } else {
            // ═══════════════════════════════════════════════════════════
            // MySQL
            // ═══════════════════════════════════════════════════════════
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                    uuid            CHAR(36)        NOT NULL UNIQUE,
                    nome            VARCHAR(50)     NOT NULL UNIQUE,
                    slug            VARCHAR(50)     NOT NULL UNIQUE,
                    descricao       TEXT            NULL,
                    nivel           INT             NOT NULL DEFAULT 0,
                    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
                    sistema         TINYINT(1)      NOT NULL DEFAULT 0,
                    criado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em   DATETIME        NULL,
                    
                    INDEX idx_slug (slug),
                    INDEX idx_nivel (nivel)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS permissions (
                    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                    uuid            CHAR(36)        NOT NULL UNIQUE,
                    nome            VARCHAR(100)    NOT NULL UNIQUE,
                    slug            VARCHAR(100)    NOT NULL UNIQUE,
                    descricao       TEXT            NULL,
                    modulo          VARCHAR(50)     NULL,
                    categoria       VARCHAR(50)     NULL,
                    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
                    criado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em   DATETIME        NULL,
                    
                    INDEX idx_slug (slug),
                    INDEX idx_modulo (modulo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS role_permissions (
                    role_id         INT UNSIGNED    NOT NULL,
                    permission_id   INT UNSIGNED    NOT NULL,
                    concedido_em    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    concedido_por   CHAR(36)        NULL,
                    
                    PRIMARY KEY (role_id, permission_id),
                    
                    CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id) 
                        REFERENCES roles(id) ON DELETE CASCADE,
                    CONSTRAINT fk_role_perm_permission FOREIGN KEY (permission_id) 
                        REFERENCES permissions(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuario_roles (
                    usuario_uuid    CHAR(36)        NOT NULL,
                    role_id         INT UNSIGNED    NOT NULL,
                    atribuido_em    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atribuido_por   CHAR(36)        NULL,
                    expira_em       DATETIME        NULL,
                    
                    PRIMARY KEY (usuario_uuid, role_id),
                    
                    INDEX idx_usuario (usuario_uuid),
                    
                    CONSTRAINT fk_user_role_role FOREIGN KEY (role_id) 
                        REFERENCES roles(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuario_permissions (
                    usuario_uuid    CHAR(36)        NOT NULL,
                    permission_id   INT UNSIGNED    NOT NULL,
                    tipo            VARCHAR(10)     NOT NULL DEFAULT 'grant',
                    concedido_em    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    concedido_por   CHAR(36)        NULL,
                    expira_em       DATETIME        NULL,
                    
                    PRIMARY KEY (usuario_uuid, permission_id),
                    
                    INDEX idx_usuario (usuario_uuid),
                    
                    CONSTRAINT fk_user_perm_permission FOREIGN KEY (permission_id) 
                        REFERENCES permissions(id) ON DELETE CASCADE,
                    CONSTRAINT chk_tipo CHECK (tipo IN ('grant', 'deny'))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS usuario_permissions");
        $pdo->exec("DROP TABLE IF EXISTS usuario_roles");
        $pdo->exec("DROP TABLE IF EXISTS role_permissions");
        $pdo->exec("DROP TABLE IF EXISTS permissions");
        $pdo->exec("DROP TABLE IF EXISTS roles");
    },
];
