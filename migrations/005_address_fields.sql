-- Split association address into structured fields.
-- Existing `address TEXT` is preserved as the street line; new columns add
-- city / state-province / postal / country.

USE badassHOA;

ALTER TABLE associations
    ADD COLUMN city         VARCHAR(100)         AFTER address,
    ADD COLUMN state_region VARCHAR(100)         AFTER city,
    ADD COLUMN postal_code  VARCHAR(20)          AFTER state_region,
    ADD COLUMN country      CHAR(2) DEFAULT 'US' AFTER postal_code;
