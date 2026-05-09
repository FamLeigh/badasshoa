-- Per-unit details (bedrooms, baths, sq ft, ownership %, type).
-- Two users living in the same unit share these — they're properties of the unit, not the person.

USE badassHOA;

CREATE TABLE IF NOT EXISTS units (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  unit_number VARCHAR(20) NOT NULL,
  type ENUM('condo','townhouse','single_family','apartment','other') DEFAULT 'condo',
  bedrooms TINYINT,
  baths DECIMAL(3,1),                  -- 2.5 baths
  square_footage INT,
  ownership_percent DECIMAL(7,4),      -- 0.0000 - 100.0000  (% of common expenses)
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assoc_unit (association_id, unit_number),
  KEY idx_assoc (association_id),
  CONSTRAINT fk_unit_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
