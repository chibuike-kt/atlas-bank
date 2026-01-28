-- Expand transfer status space + failure reason
ALTER TABLE transfers
  MODIFY COLUMN status VARCHAR(30) NOT NULL,
  ADD COLUMN failure_reason VARCHAR(120) NULL;

CREATE INDEX idx_transfers_updated_at ON transfers(updated_at);

-- Append-only transfer events
CREATE TABLE IF NOT EXISTS transfer_events (
  id CHAR(36) PRIMARY KEY,
  transfer_id CHAR(36) NOT NULL,
  actor_user_id CHAR(36) NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  reason VARCHAR(160) NULL,
  meta_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_events_transfer FOREIGN KEY (transfer_id) REFERENCES transfers(id),
  CONSTRAINT fk_transfer_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_transfer_events_transfer ON transfer_events(transfer_id);
CREATE INDEX idx_transfer_events_created ON transfer_events(created_at);
