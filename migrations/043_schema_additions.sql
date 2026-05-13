-- Three additive schema changes for the 2026-05-12 feature batch:
--   1. login_attempts.kind — lets forgot.php record password-reset attempts
--      separately from login attempts without a new table.
--   2. work_orders.source_form_id — tracks WOs converted from form submissions.
--   3. meeting_minutes — dedicated board meeting minutes log.

ALTER TABLE login_attempts
    ADD COLUMN kind VARCHAR(30) NOT NULL DEFAULT 'login' AFTER email,
    ADD INDEX idx_kind_ip (kind, ip_address, attempted_at);

ALTER TABLE work_orders
    ADD COLUMN source_form_id INT UNSIGNED NULL AFTER source_arc_id,
    ADD INDEX idx_source_form (source_form_id);

CREATE TABLE meeting_minutes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id  INT UNSIGNED NOT NULL,
    meeting_date    DATE NOT NULL,
    meeting_type    ENUM('regular','special','annual','executive') NOT NULL DEFAULT 'regular',
    title           VARCHAR(255) NOT NULL,
    body_html       LONGTEXT NOT NULL DEFAULT '',
    attendees       TEXT NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assoc_date (association_id, meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
