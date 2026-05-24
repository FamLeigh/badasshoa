ALTER TABLE association_attractions
    ADD COLUMN address   VARCHAR(500) NULL AFTER website_url,
    ADD COLUMN latitude  DECIMAL(10,7) NULL AFTER address,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;
