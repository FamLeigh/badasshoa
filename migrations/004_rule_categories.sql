-- Categories for rules and bylaws.
-- Names are unique per association. Existing rule rows reference categories by name
-- (rules.category is VARCHAR(100)), so this table just drives the SELECT options.

USE badassHOA;

CREATE TABLE IF NOT EXISTS rule_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assoc_name (association_id, name),
  KEY idx_assoc (association_id),
  CONSTRAINT fk_rcat_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed sensible defaults for the Demo Condos association.
INSERT IGNORE INTO rule_categories (association_id, name, sort_order)
SELECT 1, name, sort_order FROM (
    SELECT 'Pets'                  AS name, 10 AS sort_order UNION ALL
    SELECT 'Common areas',                  20 UNION ALL
    SELECT 'Renovations',                   30 UNION ALL
    SELECT 'Parking',                       40 UNION ALL
    SELECT 'Noise',                         50 UNION ALL
    SELECT 'Architectural review',          60
) AS defaults
WHERE EXISTS (SELECT 1 FROM associations WHERE id = 1);
