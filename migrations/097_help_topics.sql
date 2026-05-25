-- Help topics table.
-- Replaces the hardcoded help_topics() PHP function with a DB-backed system.
-- Super admins manage topics via /admin/help.php.
-- min_role controls which role tier can see each topic in /dashboard/help.php.

CREATE TABLE IF NOT EXISTS help_topics (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(100)     NOT NULL,
  title       VARCHAR(255)     NOT NULL,
  category    VARCHAR(100)     NOT NULL,
  min_role    ENUM('renter','staff','owner','property_manager','board_member','board_admin','super_admin')
              NOT NULL DEFAULT 'renter',
  body        MEDIUMTEXT       NOT NULL,
  youtube_url VARCHAR(500)     NULL DEFAULT NULL,
  images      JSON             NULL DEFAULT NULL,
  sort_order  SMALLINT         NOT NULL DEFAULT 0,
  active      TINYINT(1)       NOT NULL DEFAULT 1,
  created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY  uk_help_slug     (slug),
  KEY         k_help_category  (category),
  KEY         k_help_order     (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;