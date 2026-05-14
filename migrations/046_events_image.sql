-- Add image support to events (Phase 2 — fun printouts)
ALTER TABLE events ADD COLUMN image_path VARCHAR(500) NULL AFTER description;
