-- Per-unit ownership + tenancy + per-unit documents.
-- Three additive changes:
--   1. unit_occupants: many-to-many between units and users (owner / co_owner / tenant)
--   2. documents.unit_id: optional FK to units; NULL = association-wide (existing behavior)
--   3. documents.access_level gains 'unit_only' (visible to that unit's occupants + managers)
-- All additive — existing rows untouched.

USE badassHOA;

-- 1. Many-to-many table for occupants
CREATE TABLE IF NOT EXISTS unit_occupants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id    INT  NOT NULL,
  user_id    INT  NOT NULL,
  role       ENUM('owner','co_owner','tenant') NOT NULL DEFAULT 'owner',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  since      DATE NULL,
  notes      TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_unit_user (unit_id, user_id),
  KEY idx_unit (unit_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_uo_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
  CONSTRAINT fk_uo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Optional FK on documents → units
ALTER TABLE documents
    ADD COLUMN unit_id INT NULL AFTER association_id,
    ADD KEY idx_doc_unit (unit_id),
    ADD CONSTRAINT fk_docs_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL;

-- 3. Extend access_level enum (purely additive — existing values unchanged).
ALTER TABLE documents
    MODIFY COLUMN access_level ENUM('public','members_only','board_only','unit_only') DEFAULT 'members_only';
