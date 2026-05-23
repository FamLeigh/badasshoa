-- Board meeting planner: the meeting shell record.
-- agenda_items and resolutions in 077/078.
CREATE TABLE board_meetings (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id          INT UNSIGNED NOT NULL,
    title                   VARCHAR(255) NOT NULL,
    meeting_type            ENUM('regular','special','annual','executive') NOT NULL DEFAULT 'regular',
    meeting_date            DATE NOT NULL,
    meeting_time            TIME NULL,
    location                VARCHAR(500) NULL,
    -- Virtual / hybrid meeting info
    virtual_platform        ENUM('none','zoom','google_meet','teams','webex','other') NOT NULL DEFAULT 'none',
    virtual_url             VARCHAR(1000) NULL,
    virtual_meeting_id      VARCHAR(100) NULL,
    virtual_passcode        VARCHAR(100) NULL,
    virtual_phone_numbers   TEXT NULL,          -- JSON: [{label, number}]
    virtual_sip             VARCHAR(255) NULL,
    virtual_notes           TEXT NULL,
    -- Workflow
    status                  ENUM('draft','notice_posted','completed','cancelled') NOT NULL DEFAULT 'draft',
    notice_posted_at        DATETIME NULL,
    notice_posted_by        INT UNSIGNED NULL,
    -- Proof of Notice data (filled before printing the affidavit)
    proof_state             VARCHAR(100) NULL DEFAULT 'Florida',
    proof_county            VARCHAR(200) NULL,
    proof_signed_by_user_id INT UNSIGNED NULL,
    proof_signed_date       DATE NULL,
    proof_notary_name       VARCHAR(255) NULL,
    proof_notary_commission VARCHAR(100) NULL,
    proof_notary_expires    DATE NULL,
    -- Meta
    created_by              INT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assoc_date (association_id, meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link existing minutes records to a board meeting (optional; nullable FK).
ALTER TABLE meeting_minutes
    ADD COLUMN board_meeting_id INT UNSIGNED NULL AFTER association_id,
    ADD INDEX idx_board_meeting_id (board_meeting_id);
