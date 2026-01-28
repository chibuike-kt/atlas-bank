CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Each user gets one primary account to start (you can expand later).
CREATE TABLE IF NOT EXISTS accounts (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  currency CHAR(3) NOT NULL,
  balance_minor BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Idempotency keys are critical for safe retries.
CREATE TABLE IF NOT EXISTS idempotency_keys (
  id CHAR(36) PRIMARY KEY,
  owner_user_id CHAR(36) NOT NULL,
  idem_key VARCHAR(200) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_code INT NULL,
  response_body MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_owner_key (owner_user_id, idem_key),
  CONSTRAINT fk_idem_user FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Minimal audit log (append-only).
CREATE TABLE IF NOT EXISTS audit_logs (
  id CHAR(36) PRIMARY KEY,
  actor_user_id CHAR(36) NULL,
  action VARCHAR(80) NOT NULL,
  meta_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
