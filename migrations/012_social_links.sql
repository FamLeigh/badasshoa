-- Social / external links shown in the public landing footer.
-- All optional. Empty fields don't render an icon.

USE badassHOA;

ALTER TABLE associations
    ADD COLUMN website_url   VARCHAR(500) AFTER contact_phone,
    ADD COLUMN facebook_url  VARCHAR(500) AFTER website_url,
    ADD COLUMN instagram_url VARCHAR(500) AFTER facebook_url,
    ADD COLUMN twitter_url   VARCHAR(500) AFTER instagram_url,
    ADD COLUMN nextdoor_url  VARCHAR(500) AFTER twitter_url;
