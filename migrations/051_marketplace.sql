CREATE TABLE marketplace_listings (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    association_id   INT NOT NULL,
    seller_user_id   INT NOT NULL,
    title            VARCHAR(120)  NOT NULL,
    description      TEXT          NOT NULL,
    price_cents      INT UNSIGNED  NULL DEFAULT NULL,   -- NULL = free
    category         ENUM('furniture','electronics','appliances','clothing','sports','tools','vehicles','garden','baby','other') NOT NULL DEFAULT 'other',
    condition_label  ENUM('new','like_new','good','fair','for_parts') NOT NULL DEFAULT 'good',
    photo_path       VARCHAR(300)  NULL DEFAULT NULL,
    status           ENUM('active','sold','removed') NOT NULL DEFAULT 'active',
    removed_by       INT NULL DEFAULT NULL,
    removed_reason   TEXT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketplace_assoc (association_id, status, created_at),
    KEY idx_marketplace_seller (seller_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
