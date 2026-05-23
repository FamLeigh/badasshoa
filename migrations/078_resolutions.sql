-- Formal resolutions tied to a board meeting (optionally linked to an agenda item).
-- resolution_votes tracks each board member's individual vote.
CREATE TABLE resolutions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_id            INT UNSIGNED NOT NULL,
    agenda_item_id        INT UNSIGNED NULL,
    association_id        INT UNSIGNED NOT NULL,
    sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    title                 VARCHAR(500) NOT NULL,
    body_text             TEXT NULL,
    moved_by_user_id      INT UNSIGNED NULL,
    seconded_by_user_id   INT UNSIGNED NULL,
    result                ENUM('pending','passed','failed','tabled','withdrawn') NOT NULL DEFAULT 'pending',
    result_notes          TEXT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_meeting (meeting_id),
    INDEX idx_assoc   (association_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per board member per resolution — tracks who voted what.
CREATE TABLE resolution_votes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resolution_id       INT UNSIGNED NOT NULL,
    voter_user_id       INT UNSIGNED NOT NULL,
    vote                ENUM('yes','no','abstain') NOT NULL,
    entered_by_user_id  INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_res_voter (resolution_id, voter_user_id),
    INDEX idx_resolution (resolution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
