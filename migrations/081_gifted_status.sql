-- Move 'gifted' from plan to status — it's a charitable designation, not a billing tier.
-- Revert plan ENUM to original values; add 'gifted' to status ENUM.
ALTER TABLE associations
    MODIFY COLUMN plan   ENUM('starter','growth','professional','enterprise') DEFAULT 'starter',
    MODIFY COLUMN status ENUM('active','trial','inactive','gifted')           DEFAULT 'trial';

-- Any association currently on plan='free' gets plan='starter' + status='gifted'.
UPDATE associations SET status = 'gifted', plan = 'starter' WHERE plan = 'free';
