-- Per-association contact directory: emergency lines, non-emergency lines,
-- recommended contractors, utility companies, etc. Separate from the
-- association's own main email/phone (already on the associations table).
-- Additive only.

USE badassHOA;

CREATE TABLE IF NOT EXISTS association_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  kind           ENUM('emergency','non_emergency','contractor','utility','other') NOT NULL DEFAULT 'other',
  label          VARCHAR(120) NOT NULL,
  trade          VARCHAR(80)  NULL,    -- for contractors: plumbing, electrical, HVAC, roofing, …
  phone          VARCHAR(40)  NULL,
  email          VARCHAR(255) NULL,
  url            VARCHAR(500) NULL,
  notes          TEXT         NULL,
  is_public      TINYINT(1)   NOT NULL DEFAULT 0,   -- show on public landing too?
  sort_order     INT          DEFAULT 0,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc_kind (association_id, kind),
  CONSTRAINT fk_ac_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
