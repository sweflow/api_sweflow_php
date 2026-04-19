<?php
/**
 * Migration: Módulo Usuarios2 — Tabela usuarios2
 * 
 * Tabela de usuários com recursos avançados de segurança:
 * - Autenticação multifator (2FA)
 * - Histórico de logins
 * - Bloqueio por tentativas
 * - Sessões ativas
 * - Auditoria completa
 */
return [
    'up' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            // ═══════════════════════════════════════════════════════════
            // PostgreSQL
            // ═══════════════════════════════════════════════════════════
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios2 (
                    -- Identificação
                    uuid                    UUID            NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
                    nome_completo           VARCHAR(255)    NOT NULL,
                    username                VARCHAR(50)     NOT NULL UNIQUE,
                    email                   VARCHAR(255)    NOT NULL UNIQUE,
                    
                    -- Autenticação
                    senha_hash              VARCHAR(255)    NOT NULL,
                    senha_alterada_em       TIMESTAMP       NULL,
                    senha_expira_em         TIMESTAMP       NULL,
                    requer_troca_senha      BOOLEAN         NOT NULL DEFAULT FALSE,
                    
                    -- Perfil
                    url_avatar              VARCHAR(500)    NULL,
                    url_capa                VARCHAR(500)    NULL,
                    biografia               TEXT            NULL,
                    telefone                VARCHAR(20)     NULL,
                    data_nascimento         DATE            NULL,
                    
                    -- Permissões e Status
                    nivel_acesso            VARCHAR(30)     NOT NULL DEFAULT 'usuario'
                        CHECK (nivel_acesso IN ('usuario','moderador','admin','super_admin')),
                    ativo                   BOOLEAN         NOT NULL DEFAULT TRUE,
                    bloqueado               BOOLEAN         NOT NULL DEFAULT FALSE,
                    bloqueado_em            TIMESTAMP       NULL,
                    bloqueado_motivo        TEXT            NULL,
                    bloqueado_ate           TIMESTAMP       NULL,
                    
                    -- Verificação de Email
                    email_verificado        BOOLEAN         NOT NULL DEFAULT FALSE,
                    email_verificado_em     TIMESTAMP       NULL,
                    token_verificacao_email VARCHAR(255)    NULL,
                    token_verificacao_expira TIMESTAMP      NULL,
                    
                    -- Recuperação de Senha
                    token_recuperacao_senha VARCHAR(255)    NULL,
                    token_recuperacao_expira TIMESTAMP      NULL,
                    token_recuperacao_usado BOOLEAN         NOT NULL DEFAULT FALSE,
                    
                    -- Autenticação Multifator (2FA)
                    mfa_habilitado          BOOLEAN         NOT NULL DEFAULT FALSE,
                    mfa_secret              VARCHAR(255)    NULL,
                    mfa_backup_codes        TEXT            NULL, -- JSON array
                    mfa_habilitado_em       TIMESTAMP       NULL,
                    
                    -- Segurança e Tentativas
                    tentativas_login        INTEGER         NOT NULL DEFAULT 0,
                    ultimo_login_falho      TIMESTAMP       NULL,
                    bloqueio_temporario_ate TIMESTAMP       NULL,
                    
                    -- Último Acesso
                    ultimo_login            TIMESTAMP       NULL,
                    ultimo_ip               VARCHAR(45)     NULL, -- IPv6 support
                    ultimo_user_agent       TEXT            NULL,
                    
                    -- Preferências
                    preferencias            JSONB           NULL, -- tema, idioma, notificações, etc
                    
                    -- Metadados
                    metadata                JSONB           NULL, -- dados extras customizáveis
                    
                    -- Auditoria
                    criado_em               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    criado_por              UUID            NULL,
                    atualizado_em           TIMESTAMP       NULL,
                    atualizado_por          UUID            NULL,
                    deletado_em             TIMESTAMP       NULL, -- Soft delete
                    deletado_por            UUID            NULL,
                    
                    -- Constraints
                    CONSTRAINT fk_criado_por FOREIGN KEY (criado_por) 
                        REFERENCES usuarios2(uuid) ON DELETE SET NULL,
                    CONSTRAINT fk_atualizado_por FOREIGN KEY (atualizado_por) 
                        REFERENCES usuarios2(uuid) ON DELETE SET NULL,
                    CONSTRAINT fk_deletado_por FOREIGN KEY (deletado_por) 
                        REFERENCES usuarios2(uuid) ON DELETE SET NULL
                )
            ");
            
            // Índices para performance
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_email ON usuarios2 (email)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_username ON usuarios2 (username)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_ativo ON usuarios2 (ativo)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_bloqueado ON usuarios2 (bloqueado)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_nivel_acesso ON usuarios2 (nivel_acesso)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_email_verificado ON usuarios2 (email_verificado)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_deletado_em ON usuarios2 (deletado_em)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios2_ultimo_login ON usuarios2 (ultimo_login)");
            
        } else {
            // ═══════════════════════════════════════════════════════════
            // MySQL
            // ═══════════════════════════════════════════════════════════
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios2 (
                    -- Identificação
                    uuid                    CHAR(36)        NOT NULL PRIMARY KEY,
                    nome_completo           VARCHAR(255)    NOT NULL,
                    username                VARCHAR(50)     NOT NULL UNIQUE,
                    email                   VARCHAR(255)    NOT NULL UNIQUE,
                    
                    -- Autenticação
                    senha_hash              VARCHAR(255)    NOT NULL,
                    senha_alterada_em       DATETIME        NULL,
                    senha_expira_em         DATETIME        NULL,
                    requer_troca_senha      TINYINT(1)      NOT NULL DEFAULT 0,
                    
                    -- Perfil
                    url_avatar              VARCHAR(500)    NULL,
                    url_capa                VARCHAR(500)    NULL,
                    biografia               TEXT            NULL,
                    telefone                VARCHAR(20)     NULL,
                    data_nascimento         DATE            NULL,
                    
                    -- Permissões e Status
                    nivel_acesso            VARCHAR(30)     NOT NULL DEFAULT 'usuario',
                    ativo                   TINYINT(1)      NOT NULL DEFAULT 1,
                    bloqueado               TINYINT(1)      NOT NULL DEFAULT 0,
                    bloqueado_em            DATETIME        NULL,
                    bloqueado_motivo        TEXT            NULL,
                    bloqueado_ate           DATETIME        NULL,
                    
                    -- Verificação de Email
                    email_verificado        TINYINT(1)      NOT NULL DEFAULT 0,
                    email_verificado_em     DATETIME        NULL,
                    token_verificacao_email VARCHAR(255)    NULL,
                    token_verificacao_expira DATETIME       NULL,
                    
                    -- Recuperação de Senha
                    token_recuperacao_senha VARCHAR(255)    NULL,
                    token_recuperacao_expira DATETIME       NULL,
                    token_recuperacao_usado TINYINT(1)      NOT NULL DEFAULT 0,
                    
                    -- Autenticação Multifator (2FA)
                    mfa_habilitado          TINYINT(1)      NOT NULL DEFAULT 0,
                    mfa_secret              VARCHAR(255)    NULL,
                    mfa_backup_codes        TEXT            NULL,
                    mfa_habilitado_em       DATETIME        NULL,
                    
                    -- Segurança e Tentativas
                    tentativas_login        INT             NOT NULL DEFAULT 0,
                    ultimo_login_falho      DATETIME        NULL,
                    bloqueio_temporario_ate DATETIME        NULL,
                    
                    -- Último Acesso
                    ultimo_login            DATETIME        NULL,
                    ultimo_ip               VARCHAR(45)     NULL,
                    ultimo_user_agent       TEXT            NULL,
                    
                    -- Preferências
                    preferencias            JSON            NULL,
                    
                    -- Metadados
                    metadata                JSON            NULL,
                    
                    -- Auditoria
                    criado_em               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    criado_por              CHAR(36)        NULL,
                    atualizado_em           DATETIME        NULL,
                    atualizado_por          CHAR(36)        NULL,
                    deletado_em             DATETIME        NULL,
                    deletado_por            CHAR(36)        NULL,
                    
                    -- Índices
                    INDEX idx_email (email),
                    INDEX idx_username (username),
                    INDEX idx_ativo (ativo),
                    INDEX idx_bloqueado (bloqueado),
                    INDEX idx_nivel_acesso (nivel_acesso),
                    INDEX idx_email_verificado (email_verificado),
                    INDEX idx_deletado_em (deletado_em),
                    INDEX idx_ultimo_login (ultimo_login),
                    
                    -- Foreign Keys
                    CONSTRAINT fk_criado_por FOREIGN KEY (criado_por) 
                        REFERENCES usuarios2(uuid) ON DELETE SET NULL,
                    CONSTRAINT fk_atualizado_por FOREIGN KEY (atualizado_por) 
                        REFERENCES usuarios2(uuid) ON DELETE SET NULL,
                    CONSTRAINT fk_deletado_por FOREIGN KEY (deletado_por) 
                        REFERENCES usuarios2(uuid) ON DELETE SET NULL,
                    
                    -- Constraints
                    CONSTRAINT chk_nivel_acesso CHECK (nivel_acesso IN ('usuario','moderador','admin','super_admin'))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },
    
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS usuarios2");
    },
];
