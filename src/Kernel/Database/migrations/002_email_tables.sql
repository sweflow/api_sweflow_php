-- NOTA: Este arquivo SQL é executado pelo MigrateCommand/SetupCommand via exec() direto.
-- Para suporte multi-driver (MySQL + PostgreSQL), as migrations de módulos usam PHP.
-- Este arquivo assume PostgreSQL (usado pelo kernel). Para MySQL, o Migrator usa PHP migrations.
-- Se você usa MySQL, as tabelas email_history e email_throttle são criadas pelo módulo de e-mail
-- via migration PHP com bifurcação de driver.

-- email_history: histórico de e-mails enviados pelo módulo de e-mail
CREATE TABLE IF NOT EXISTS email_history (
    id          VARCHAR(16)  PRIMARY KEY,
    subject     TEXT         NOT NULL DEFAULT '',
    recipients  JSONB        NOT NULL DEFAULT '[]',
    html        TEXT         NOT NULL DEFAULT '',
    logo_url    TEXT,
    status      VARCHAR(20)  NOT NULL DEFAULT 'enviado',
    error       TEXT,
    resent_from VARCHAR(16),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_history_status     ON email_history (status);
CREATE INDEX IF NOT EXISTS idx_email_history_created_at ON email_history (created_at DESC);

-- email_throttle: rate-limit de disparos de e-mail (verificação, recuperação de senha, etc.)
CREATE TABLE IF NOT EXISTS email_throttle (
    type    VARCHAR(50)  NOT NULL,
    email   VARCHAR(255) NOT NULL,
    sent_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    PRIMARY KEY (type, email)
);

CREATE INDEX IF NOT EXISTS idx_email_throttle_sent_at ON email_throttle (sent_at);
