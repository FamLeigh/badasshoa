ALTER TABLE property_listings
    ADD COLUMN seller_user_id INT UNSIGNED NULL AFTER association_id;

CREATE TABLE listing_photos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id  INT UNSIGNED NOT NULL,
    photo_path  VARCHAR(500) NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_listing (listing_id),
    CONSTRAINT fk_lp_listing FOREIGN KEY (listing_id)
        REFERENCES property_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
