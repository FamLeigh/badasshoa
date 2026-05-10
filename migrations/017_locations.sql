-- Building locations — curated picklist for events, and the foundation for
-- maintenance work orders down the line. events.location stays VARCHAR free-text
-- so existing data is undisturbed; this table just powers the autocomplete and
-- gives a place for descriptions / ordering.

USE badassHOA;

CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  name           VARCHAR(120) NOT NULL,
  description    TEXT NULL,
  sort_order     INT DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assoc_name (association_id, name),
  KEY idx_assoc_active (association_id, is_active),
  CONSTRAINT fk_loc_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
