-- Per-user opt-in for "Meet your board" listing on the public landing.
-- Default 0 (off) so existing board members aren't exposed without consent.

USE badassHOA;

ALTER TABLE users
    ADD COLUMN show_on_public_landing TINYINT(1) NOT NULL DEFAULT 0 AFTER is_owner;
