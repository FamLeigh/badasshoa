CREATE TABLE newsletter_subscribers (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    association_id   INT NOT NULL,
    email            VARCHAR(254)  NOT NULL,
    name             VARCHAR(120)  NULL DEFAULT NULL,
    status           ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
    token            VARCHAR(64)   NOT NULL,
    subscribed_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at  TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_newsletter (association_id, email),
    KEY idx_newsletter_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
