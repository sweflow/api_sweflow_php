-- ============================================================
-- Sweflow API — Migração de Segurança
-- Tabelas: audit_logs, login_attempts
-- ============================================================

-- Tabela de auditoria de ações de segurança
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGSERIAL PRIMARY KEY,
    evento      VARCHAR(100)  NOT NULL,
    usuario_uuid UUID          NULL,
    contexto    JSONB         NOT NULL DEFAULT '{}',
    ip          VARCHAR(45)   NOT NULL DEFAULT '',
    user_agent  VARCHAR(512)  NOT NULL DEFAULT '',
    endpoint    VARCHAR(255)  NOT NULL DEFAULT '',
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_evento       ON audit_logs (evento);
CREATE INDEX IF NOT EXISTS idx_audit_logs_usuario      ON audit_logs (usuario_uuid);
CREATE INDEX IF NOT EXISTS idx_audit_logs_ip           ON audit_logs (ip);
CREATE INDEX IF NOT EXISTS idx_audit_logs_criado_em    ON audit_logs (criado_em DESC);

-- Tabela de tentativas de login (brute force tracking)
CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGSERIAL PRIMARY KEY,
    identifier  VARCHAR(255) NOT NULL,  -- email ou username tentado
    ip          VARCHAR(45)  NOT NULL,
    sucesso     BOOLEAN      NOT NULL DEFAULT FALSE,
    criado_em   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip         ON login_attempts (ip, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier ON login_attempts (identifier, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_criado_em  ON login_attempts (criado_em DESC);

-- Limpeza automática de tentativas antigas (> 24h)
-- Executar periodicamente via cron ou no boot
-- DELETE FROM login_attempts WHERE criado_em < NOW() - INTERVAL '24 hours';
