-- Add floor_plan_doc_id FK to units.
-- Points to a single documents row per unit type; many units share one doc.
-- ON DELETE SET NULL so deleting a floor plan doc doesn't cascade-delete units.

ALTER TABLE units
    ADD COLUMN floor_plan_doc_id INT NULL DEFAULT NULL AFTER baths,
    ADD CONSTRAINT fk_unit_floor_plan
        FOREIGN KEY (floor_plan_doc_id) REFERENCES documents(id) ON DELETE SET NULL;
