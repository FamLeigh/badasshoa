-- Capture structured address on signup intake.
-- Mirrors the columns added to associations in 005, so the approve-signup flow
-- can copy them straight across when provisioning a new tenant.

USE badassHOA;

ALTER TABLE signups
    ADD COLUMN address      VARCHAR(500)         AFTER association_name,
    ADD COLUMN city         VARCHAR(100)         AFTER address,
    ADD COLUMN state_region VARCHAR(100)         AFTER city,
    ADD COLUMN postal_code  VARCHAR(20)          AFTER state_region,
    ADD COLUMN country      CHAR(2) DEFAULT 'US' AFTER postal_code;
