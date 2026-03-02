-- refresh_tokens
CREATE TABLE refresh_tokens (
  jti CHAR(36) NOT NULL,
  user_uuid CHAR(36) NOT NULL,
  token_hash TEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (jti),
  INDEX idx_refresh_tokens_user (user_uuid),
  INDEX idx_refresh_tokens_expires (expires_at),
  INDEX idx_refresh_tokens_revoked (revoked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- revoked_access_tokens
CREATE TABLE revoked_access_tokens (
  jti CHAR(36) NOT NULL,
  user_uuid CHAR(36) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (jti),
  INDEX idx_revoked_access_tokens_user (user_uuid),
  INDEX idx_revoked_access_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- usuarios
CREATE TABLE usuarios (
  uuid CHAR(36) NOT NULL,
  nome_completo VARCHAR(255) NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(255) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  url_avatar VARCHAR(255) NULL,
  url_capa VARCHAR(255) NULL,
  biografia TEXT NULL,
  nivel_acesso VARCHAR(20) NULL,
  token_recuperacao_senha VARCHAR(255) NULL,
  token_verificacao_email VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  verificado_email TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL,
  status_verificacao VARCHAR(30) NOT NULL DEFAULT 'Não verificado',
  PRIMARY KEY (uuid),
  UNIQUE KEY usuarios_email_key (email),
  UNIQUE KEY usuarios_username_key (username),
  CONSTRAINT usuarios_nivel_acesso_check
    CHECK (nivel_acesso IN ('usuario','admin','moderador','admin_system'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;