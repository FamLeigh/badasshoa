-- 031: Schedule + expiry on announcements
--
-- published_at already exists (since 001) and acts as "starts at" — the
-- announcement isn't visible to members until that timestamp. Adding
-- expires_at so the board can post something like a 2-week pool-closure
-- notice and have it auto-disappear afterwards.
--
-- NULL expires_at = never expires (current behavior).
ALTER TABLE announcements
    ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL AFTER published_at,
    ADD KEY idx_assoc_expires (association_id, expires_at);
