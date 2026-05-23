-- Expand vote options: add not_present and na.
-- not_present = board member was absent; na = recused / conflict of interest.
-- Neither counts toward the yes/no tally for pass/fail.
ALTER TABLE resolution_votes
    MODIFY COLUMN vote ENUM('yes','no','abstain','not_present','na') NOT NULL;
