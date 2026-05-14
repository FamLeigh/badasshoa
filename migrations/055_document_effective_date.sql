-- Add effective_date to documents table.
-- Also carried by rules already; this lets the board stamp "effective as of" on bylaws, policies, etc.

ALTER TABLE documents
    ADD COLUMN effective_date DATE NULL DEFAULT NULL AFTER description;
