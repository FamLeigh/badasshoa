ALTER TABLE votes
    ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD KEY idx_votes_archived (association_id, archived, status);
