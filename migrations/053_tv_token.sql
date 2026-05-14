ALTER TABLE associations
    ADD COLUMN tv_token VARCHAR(64) NULL DEFAULT NULL AFTER subdomain,
    ADD UNIQUE KEY uq_assoc_tv_token (tv_token);
