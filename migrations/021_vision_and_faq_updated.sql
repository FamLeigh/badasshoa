-- Vision statement on associations (public landing display) + updated_at
-- timestamp on FAQs so the dashboard can show "last updated" alongside each
-- entry. Both additive.

USE badassHOA;

ALTER TABLE associations
    ADD COLUMN vision_statement TEXT NULL AFTER address;

ALTER TABLE faqs
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
