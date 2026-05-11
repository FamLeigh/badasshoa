-- 035: Insurance policies + contractor COIs (certificates of insurance)
--
-- One table covers both — `kind` distinguishes:
--   policy        = the association's own insurance (property, liability,
--                   D&O, umbrella, flood, earthquake…)
--   contractor_coi = certificate of insurance held by a contractor working
--                   for the association (their proof of GL/WC coverage)
--
-- Renewal warnings on the dashboard fire when expires_at is within 30 days.
-- Optional document_id links to a documents row for the actual PDF.

CREATE TABLE IF NOT EXISTS insurance_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    association_id  INT NOT NULL,
    kind            ENUM('policy','contractor_coi') NOT NULL DEFAULT 'policy',
    policy_type     VARCHAR(120) NULL,   -- 'Property', 'GL', 'D&O', 'WC', 'Umbrella', …
    carrier         VARCHAR(160) NOT NULL,
    policy_number   VARCHAR(160) NULL,
    coverage_amount DECIMAL(14,2) NULL,
    annual_premium  DECIMAL(12,2) NULL,
    deductible      DECIMAL(12,2) NULL,
    contractor_contact_id INT NULL,      -- for contractor_coi: links to association_contacts
    contractor_name VARCHAR(255) NULL,   -- fallback when no contact row exists yet
    document_id     INT NULL,            -- optional pointer to the actual PDF
    effective_at    DATE NULL,
    expires_at      DATE NULL,
    agent_name      VARCHAR(255) NULL,
    agent_phone     VARCHAR(80) NULL,
    agent_email     VARCHAR(255) NULL,
    notes           TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assoc_kind_exp (association_id, kind, expires_at),
    CONSTRAINT fk_ins_assoc      FOREIGN KEY (association_id)        REFERENCES associations(id)         ON DELETE CASCADE,
    CONSTRAINT fk_ins_contractor FOREIGN KEY (contractor_contact_id) REFERENCES association_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_ins_doc        FOREIGN KEY (document_id)           REFERENCES documents(id)            ON DELETE SET NULL
) ENGINE=InnoDB;
