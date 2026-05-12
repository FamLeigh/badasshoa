-- 038: Resident forms (guest registration, temp parking pass, etc.)
--
-- Single table covers all form types via form_type ENUM + a flexible payload
-- JSON column for per-form fields. Submissions are SELF-SERVICE — auto-issued
-- with a printable confirmation_code, board sees the log + can revoke. New
-- form types add via the enum (no schema change beyond a one-line ALTER).
--
-- Common fields on the table (used by every form type):
--   form_type           — which form
--   unit_id             — which unit the form is for
--   submitter_user_id   — who filled it out
--   starts_at / ends_at — time window (e.g. guest visit dates)
--   confirmation_code   — short shareable code (e.g. "AB72-9XKM")
--   status              — issued / revoked / expired
--   payload             — per-form fields as JSON (vehicle plate, guest name, etc.)
--
-- Additive — no existing data touched.

CREATE TABLE IF NOT EXISTS form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    association_id      INT NOT NULL,
    form_type           ENUM('guest_registration','parking_pass','move_in','move_out','key_request','other')
                        NOT NULL DEFAULT 'guest_registration',
    unit_id             INT NULL,
    submitter_user_id   INT NULL,
    title               VARCHAR(255) NULL,
    starts_at           DATE NULL,
    ends_at             DATE NULL,
    confirmation_code   VARCHAR(20) NOT NULL,
    status              ENUM('issued','revoked','expired') NOT NULL DEFAULT 'issued',
    payload             JSON NULL,
    notes               TEXT NULL,
    revoked_at          TIMESTAMP NULL,
    revoked_by_user_id  INT NULL,
    revoke_reason       TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_code (association_id, confirmation_code),
    KEY idx_assoc_type_status (association_id, form_type, status),
    KEY idx_unit              (unit_id),
    KEY idx_submitter         (submitter_user_id),
    KEY idx_window            (starts_at, ends_at),
    CONSTRAINT fk_form_assoc      FOREIGN KEY (association_id)     REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_unit       FOREIGN KEY (unit_id)            REFERENCES units(id)        ON DELETE SET NULL,
    CONSTRAINT fk_form_submitter  FOREIGN KEY (submitter_user_id)  REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_form_revoker    FOREIGN KEY (revoked_by_user_id) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB;
