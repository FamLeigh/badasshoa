-- Add "Resolutions" document category (sort_order 25, between Minutes and Insurance)
-- for all existing associations that already have default categories.
INSERT IGNORE INTO document_categories (association_id, name, sort_order)
SELECT DISTINCT association_id, 'Resolutions', 25
  FROM document_categories;
