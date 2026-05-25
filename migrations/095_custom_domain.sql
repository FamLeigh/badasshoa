ALTER TABLE associations
    ADD COLUMN custom_domain VARCHAR(190) NULL DEFAULT NULL AFTER subdomain,
    ADD UNIQUE KEY idx_custom_domain (custom_domain);
