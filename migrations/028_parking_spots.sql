-- Per-association parking spot directory: garages, surface spots, covered,
-- tandem, etc. Each spot can be assigned to one unit (FK SET NULL on delete
-- so unassigning is easy). units.garage_number / units.parking_spot remain
-- as string hints — this table is the structured record.

USE badassHOA;

CREATE TABLE IF NOT EXISTS parking_spots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id   INT NOT NULL,
  kind             ENUM('garage','surface','covered','tandem','other') NOT NULL DEFAULT 'garage',
  number           VARCHAR(20) NOT NULL,
  assigned_unit_id INT NULL,
  notes            TEXT NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  sort_order       INT DEFAULT 0,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assoc_kind_number (association_id, kind, number),
  KEY idx_assoc_kind (association_id, kind),
  KEY idx_assigned   (assigned_unit_id),
  CONSTRAINT fk_ps_assoc FOREIGN KEY (association_id)   REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_unit  FOREIGN KEY (assigned_unit_id) REFERENCES units(id)        ON DELETE SET NULL
) ENGINE=InnoDB;
