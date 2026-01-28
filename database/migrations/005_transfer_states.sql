ALTER TABLE transfers
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'posted',
  ADD COLUMN reversal_journal_id CHAR(36) NULL,
  ADD COLUMN reversed_at TIMESTAMP NULL,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE transfers
  ADD CONSTRAINT fk_transfers_reversal_journal
  FOREIGN KEY (reversal_journal_id) REFERENCES journals(id);

CREATE INDEX idx_transfers_status ON transfers(status);
CREATE INDEX idx_transfers_reversal_journal ON transfers(reversal_journal_id);
