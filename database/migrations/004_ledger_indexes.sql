CREATE INDEX idx_postings_journal ON postings(journal_id);
CREATE INDEX idx_postings_account ON postings(account_id);
CREATE INDEX idx_transfers_sender ON transfers(sender_user_id);
CREATE INDEX idx_transfers_recipient ON transfers(recipient_user_id);
CREATE INDEX idx_journals_type ON journals(type);
