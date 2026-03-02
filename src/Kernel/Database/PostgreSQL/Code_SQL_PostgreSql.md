-- Tabelas
CREATE TABLE public.refresh_tokens (
    jti uuid PRIMARY KEY,
    user_uuid uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamptz NOT NULL,
    revoked boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.revoked_access_tokens (
    jti uuid PRIMARY KEY,
    user_uuid uuid NOT NULL,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.usuarios (
    uuid uuid PRIMARY KEY,
    nome_completo varchar(255) NOT NULL,
    username varchar(50) NOT NULL,
    email varchar(255) NOT NULL,
    senha_hash varchar(255) NOT NULL,
    url_avatar varchar(255),
    url_capa varchar(255),
    biografia text,
    nivel_acesso varchar(20),
    token_recuperacao_senha varchar(255),
    token_verificacao_email varchar(255),
    ativo boolean DEFAULT true,
    verificado_email boolean DEFAULT false,
    criado_em timestamp WITHOUT time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em timestamp WITHOUT time zone,
    status_verificacao varchar(30) DEFAULT 'Não verificado',
    CONSTRAINT usuarios_nivel_acesso_check CHECK (
        nivel_acesso IN ('usuario','admin','moderador','admin_system')
    ),
    CONSTRAINT usuarios_email_key UNIQUE (email),
    CONSTRAINT usuarios_username_key UNIQUE (username)
);

-- Índices
CREATE INDEX idx_refresh_tokens_expires ON public.refresh_tokens (expires_at);
CREATE INDEX idx_refresh_tokens_revoked ON public.refresh_tokens (revoked);
CREATE INDEX idx_refresh_tokens_user ON public.refresh_tokens (user_uuid);

CREATE INDEX idx_revoked_access_tokens_expires ON public.revoked_access_tokens (expires_at);
CREATE INDEX idx_revoked_access_tokens_user ON public.revoked_access_tokens (user_uuid);