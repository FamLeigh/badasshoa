-- "Flag for review" on rules — board can mark a rule for follow-up
-- (legal review, outdated language, conflicting with another rule, etc.)
-- with an optional note. Purely additive.

USE badassHOA;

ALTER TABLE rules
    ADD COLUMN review_flag               TINYINT(1) NOT NULL DEFAULT 0 AFTER effective_date,
    ADD COLUMN review_note               TEXT       NULL                AFTER review_flag,
    ADD COLUMN review_flagged_at         TIMESTAMP  NULL                AFTER review_note,
    ADD COLUMN review_flagged_by_user_id INT        NULL                AFTER review_flagged_at,
    ADD KEY idx_review (association_id, review_flag);
