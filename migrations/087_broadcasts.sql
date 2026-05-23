-- Broadcast emails: per-association email campaigns with opt-out and delivery tracking.

ALTER TABLE users
    ADD COLUMN email_broadcast_opt_out TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN email_bounce_count       INT        NOT NULL DEFAULT 0,
    ADD COLUMN last_bounced_at          DATETIME   NULL;

CREATE TABLE broadcasts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id     INT UNSIGNED NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    subject            VARCHAR(255) NOT NULL,
    body_html          MEDIUMTEXT   NOT NULL,
    body_text          TEXT         NOT NULL,
    audience           ENUM('all','owners','renters','board') NOT NULL DEFAULT 'all',
    tier               ENUM('optional','required')            NOT NULL DEFAULT 'optional',
    status             ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
    scheduled_at       DATETIME NULL,
    sent_at            DATETIME NULL,
    recipient_count    INT NOT NULL DEFAULT 0,
    sent_count         INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT NOW(),
    INDEX idx_assoc (association_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE broadcast_recipients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    broadcast_id    INT UNSIGNED NOT NULL,
    association_id  INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NULL,
    email           VARCHAR(255) NOT NULL,
    name            VARCHAR(120) NULL,
    status          ENUM('pending','sent','bounced','complained','unsubscribed','failed') NOT NULL DEFAULT 'pending',
    provider_msg_id VARCHAR(255) NULL,
    error           TEXT NULL,
    sent_at         DATETIME NULL,
    INDEX idx_broadcast (broadcast_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
