-- Headshot path + short "about me" bio on user records. Both nullable / additive.
-- avatar_path stores a relative path under /storage/uploads/{assoc}/avatars/…;
-- served through /branding.php?user_id=… or a similar gatekeeper.

USE badassHOA;

ALTER TABLE users
    ADD COLUMN avatar_path VARCHAR(500) NULL AFTER mailing_country,
    ADD COLUMN bio         TEXT         NULL AFTER avatar_path;
