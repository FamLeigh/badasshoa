-- Store a one-time invite token per user so board admins can email a resident
-- a self-service link to set their own password.
ALTER TABLE users
    ADD COLUMN invite_token     VARCHAR(64)  NULL AFTER password_hash,
    ADD COLUMN invite_sent_at   DATETIME     NULL AFTER invite_token,
    ADD COLUMN invite_expires_at DATETIME    NULL AFTER invite_sent_at;
