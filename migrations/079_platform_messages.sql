-- Messages pushed from the BadassHOA platform to a specific association.
-- Shown as a banner on the dashboard. association_id = NULL means all associations.
CREATE TABLE platform_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id  INT UNSIGNED NULL,          -- NULL = show to every association
    message         TEXT NOT NULL,
    audience        ENUM('all','board') NOT NULL DEFAULT 'all',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    expires_at      DATETIME NULL,              -- NULL = never expires
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_assoc  (association_id),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
