-- NOTA: Este arquivo SQL é executado pelo MigrateCommand/SetupCommand via exec() direto.
-- Usa sintaxe PostgreSQL (BIGSERIAL, JSONB, TIMESTAMPTZ).
-- Para MySQL, o Migrator usa migrations PHP com bifurcação de driver.
-- Se você usa MySQL, estas tabelas são criadas automaticamente pelo index.php via CREATE TABLE IF NOT EXISTS
-- com sintaxe MySQL (BIGINT AUTO_INCREMENT, JSON, DATETIME).

CREATE TABLE IF NOT EXISTS audit_logs (
    id           BIGSERIAL    PRIMARY KEY,
    evento       VARCHAR(100) NOT NULL,
    usuario_uuid UUID         NULL,
    contexto     JSONB        NOT NULL DEFAULT '{}',
    ip           VARCHAR(45)  NOT NULL DEFAULT '',
    user_agent   VARCHAR(512) NOT NULL DEFAULT '',
    endpoint     VARCHAR(255) NOT NULL DEFAULT '',
    criado_em    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_evento    ON audit_logs (evento);
CREATE INDEX IF NOT EXISTS idx_audit_logs_usuario   ON audit_logs (usuario_uuid);
CREATE INDEX IF NOT EXISTS idx_audit_logs_ip        ON audit_logs (ip);
CREATE INDEX IF NOT EXISTS idx_audit_logs_criado_em ON audit_logs (criado_em DESC);

CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGSERIAL    PRIMARY KEY,
    identifier  VARCHAR(255) NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    sucesso     BOOLEAN      NOT NULL DEFAULT FALSE,
    criado_em   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip         ON login_attempts (ip, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier ON login_attempts (identifier, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_criado_em  ON login_attempts (criado_em DESC);
