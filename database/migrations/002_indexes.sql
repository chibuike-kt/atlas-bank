CREATE INDEX idx_accounts_user ON accounts(user_id);
CREATE INDEX idx_audit_actor ON audit_logs(actor_user_id);
CREATE INDEX idx_idem_created ON idempotency_keys(created_at);
