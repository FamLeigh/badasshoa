-- Garage / parking spot numbers on units (often differ from unit_number —
-- a unit owner may have a totally different garage number, e.g. unit 421
-- with garage 64) and an optional category on locations so garages can be
-- grouped distinct from event spaces.
-- All additive — nothing existing changes.

USE badassHOA;

ALTER TABLE units
    ADD COLUMN garage_number VARCHAR(20) NULL AFTER ownership_percent,
    ADD COLUMN parking_spot  VARCHAR(20) NULL AFTER garage_number;

ALTER TABLE locations
    ADD COLUMN category VARCHAR(50) NULL AFTER name,
    ADD KEY idx_assoc_category (association_id, category);
