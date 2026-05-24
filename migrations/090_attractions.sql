CREATE TABLE association_attractions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    association_id INT UNSIGNED NOT NULL,
    name           VARCHAR(200) NOT NULL,
    description    TEXT NULL,
    website_url    VARCHAR(500) NULL,
    photo_path     VARCHAR(500) NULL,
    category       ENUM('dining','shopping','entertainment','outdoor','culture','services','other') NOT NULL DEFAULT 'other',
    sort_order     SMALLINT NOT NULL DEFAULT 0,
    active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assoc_active (association_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
