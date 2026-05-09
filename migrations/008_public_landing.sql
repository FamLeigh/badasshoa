-- Per-association toggle for the public-facing community landing page at /{slug}/
-- Default OFF — boards opt in.

USE badassHOA;

ALTER TABLE associations
    ADD COLUMN public_landing_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
