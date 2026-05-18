-- Migration 064: Add tv_pin to associations for easy TV access
-- Replaces the 48-char hex token with a memorable 6-digit PIN.
-- Old tv_token still works for backwards compat.

ALTER TABLE associations
  ADD COLUMN tv_pin VARCHAR(8) NULL DEFAULT NULL AFTER tv_token;

-- Generate a random 6-digit PIN for every existing association.
UPDATE associations
   SET tv_pin = LPAD(FLOOR(100000 + (RAND() * 899999)), 6, '0')
 WHERE tv_pin IS NULL;
