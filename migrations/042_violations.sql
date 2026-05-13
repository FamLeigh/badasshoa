-- Formal violation tracking: violations + per-notice records.
-- Each violation has a status machine: open → notice_sent → cured | escalated → fined → closed.
-- Each issued notice (warning / cure / fine / hearing) is a row in violation_notices.

CREATE TABLE violations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id  INT UNSIGNED NOT NULL,
    unit_id         INT UNSIGNED NULL,
    user_id         INT UNSIGNED NULL,       -- the violator (nullable if unit-only)
    concern_id      INT UNSIGNED NULL,       -- source concern if converted
    rule_id         INT UNSIGNED NULL,       -- primary cited rule
    violation_type  ENUM(
                        'noise_disturbance','parking_violation','pet_violation',
                        'unauthorized_modification','lease_violation',
                        'common_area_misuse','maintenance_cleanliness',
                        'rule_violation','other'
                    ) NOT NULL DEFAULT 'rule_violation',
    description     TEXT NOT NULL,
    status          ENUM('open','notice_sent','cured','escalated','fined','closed')
                    NOT NULL DEFAULT 'open',
    created_by      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assoc_status (association_id, status),
    INDEX idx_unit         (unit_id),
    INDEX idx_concern      (concern_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE violation_notices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    violation_id    INT UNSIGNED NOT NULL,
    notice_type     ENUM('warning','cure','fine','hearing') NOT NULL DEFAULT 'warning',
    due_date        DATE NULL,
    fine_amount_cents INT UNSIGNED NULL,
    body_text       TEXT NOT NULL,
    issued_by       INT UNSIGNED NOT NULL,
    issued_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_violation (violation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
