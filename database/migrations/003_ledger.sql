-- Journals: one journal event per money movement
CREATE TABLE IF NOT EXISTS journals (
  id CHAR(36) PRIMARY KEY,
  type VARCHAR(40) NOT NULL, -- e.g. internal_transfer
  reference VARCHAR(80) NOT NULL, -- external ref / idempotency ref
  status VARCHAR(20) NOT NULL, -- posted|reversed|pending
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reference (reference)
) ENGINE=InnoDB;

-- Transfers: domain record for internal transfers
CREATE TABLE IF NOT EXISTS transfers (
  id CHAR(36) PRIMARY KEY,
  journal_id CHAR(36) NOT NULL,
  sender_user_id CHAR(36) NOT NULL,
  sender_account_id CHAR(36) NOT NULL,
  recipient_user_id CHAR(36) NOT NULL,
  recipient_account_id CHAR(36) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  memo VARCHAR(180) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfers_journal FOREIGN KEY (journal_id) REFERENCES journals(id),
  CONSTRAINT fk_transfers_sender_user FOREIGN KEY (sender_user_id) REFERENCES users(id),
  CONSTRAINT fk_transfers_recipient_user FOREIGN KEY (recipient_user_id) REFERENCES users(id),
  CONSTRAINT fk_transfers_sender_acct FOREIGN KEY (sender_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_transfers_recipient_acct FOREIGN KEY (recipient_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

-- Postings: double-entry lines
-- direction: 'debit' or 'credit'
CREATE TABLE IF NOT EXISTS postings (
  id CHAR(36) PRIMARY KEY,
  journal_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  direction VARCHAR(6) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_postings_journal FOREIGN KEY (journal_id) REFERENCES journals(id),
  CONSTRAINT fk_postings_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
