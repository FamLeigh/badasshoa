-- Document categories — drives the SELECT options on /dashboard/documents.php.
-- Mirrors rule_categories. documents.category remains VARCHAR(100); this table
-- just curates the picklist. Renaming a category here also updates existing
-- documents.category values (handled in PHP, see dashboard/documents.php).
-- Defaults are bootstrapped per-association in PHP on first visit so this
-- migration applies cleanly to existing AND future associations.

USE badassHOA;

CREATE TABLE IF NOT EXISTS document_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assoc_name (association_id, name),
  KEY idx_assoc (association_id),
  CONSTRAINT fk_dcat_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
