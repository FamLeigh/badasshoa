-- Agenda items for a board meeting.
-- Standard items (call_to_order, proof_of_notice, etc.) are auto-inserted in PHP on meeting create.
-- proposed_by_user_id = the member who proposed the item.
-- entered_by_user_id  = who typed it in (may differ; manager can enter on behalf of a member).
CREATE TABLE agenda_items (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_id            INT UNSIGNED NOT NULL,
    association_id        INT UNSIGNED NOT NULL,
    sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    category              ENUM(
        'call_to_order','proof_of_notice','certify_quorum',
        'officers_report','old_business','new_business',
        'motion_to_adjourn','public_comments','custom'
    ) NOT NULL DEFAULT 'custom',
    title                 VARCHAR(500) NOT NULL,
    description           TEXT NULL,
    proposed_by_user_id   INT UNSIGNED NULL,
    entered_by_user_id    INT UNSIGNED NULL,
    -- proposed = waiting for board approval; approved = on the agenda; tabled/deferred = pushed out
    status                ENUM('proposed','approved','tabled','deferred','removed') NOT NULL DEFAULT 'proposed',
    outcome_notes         TEXT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_meeting  (meeting_id),
    INDEX idx_assoc    (association_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
