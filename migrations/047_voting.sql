-- Owner voting module: ballots, questions, options, responses, participants.
-- vote_participants tracks who voted (for quorum + dedup) independently from
-- vote_responses so anonymous ballots don't leak identity via the response row.

CREATE TABLE votes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    association_id INT NOT NULL,
    title          VARCHAR(255) NOT NULL,
    description    TEXT NULL,
    type           ENUM('election','bylaw_amendment','budget_approval','general_motion','survey')
                   NOT NULL DEFAULT 'general_motion',
    status         ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    anonymous      TINYINT(1) NOT NULL DEFAULT 0,
    allow_abstain  TINYINT(1) NOT NULL DEFAULT 1,
    quorum_pct     DECIMAL(5,2) NULL COMMENT 'Required % of eligible voters. NULL = no requirement.',
    starts_at      TIMESTAMP NULL,
    ends_at        TIMESTAMP NULL,
    results_visible ENUM('always','after_close','board_only') NOT NULL DEFAULT 'after_close',
    created_by     INT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assoc_status (association_id, status),
    CONSTRAINT fk_votes_assoc   FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_creator FOREIGN KEY (created_by)     REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vote_questions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    vote_id    INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    question   TEXT NOT NULL,
    type       ENUM('yes_no','multiple_choice','text') NOT NULL DEFAULT 'yes_no',
    required   TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_vq_vote (vote_id),
    CONSTRAINT fk_vq_vote FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vote_options (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    question_id  INT NOT NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    option_text  VARCHAR(500) NOT NULL,
    KEY idx_vo_q (question_id),
    CONSTRAINT fk_vo_question FOREIGN KEY (question_id) REFERENCES vote_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vote_responses (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    vote_id          INT NOT NULL,
    question_id      INT NOT NULL,
    user_id          INT NULL COMMENT 'NULL when ballot is anonymous',
    answer_option_id INT NULL COMMENT 'Set for yes_no and multiple_choice',
    answer_text      VARCHAR(1000) NULL COMMENT 'Set for text questions or yes/no/abstain label',
    cast_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vr_vote_q (vote_id, question_id),
    CONSTRAINT fk_vr_vote     FOREIGN KEY (vote_id)          REFERENCES votes(id)        ON DELETE CASCADE,
    CONSTRAINT fk_vr_question FOREIGN KEY (question_id)      REFERENCES vote_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_vr_option   FOREIGN KEY (answer_option_id) REFERENCES vote_options(id)  ON DELETE SET NULL,
    CONSTRAINT fk_vr_user     FOREIGN KEY (user_id)          REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Primary key prevents duplicate votes; user_id alone enforces one-per-user.
CREATE TABLE vote_participants (
    vote_id  INT NOT NULL,
    user_id  INT NOT NULL,
    voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (vote_id, user_id),
    CONSTRAINT fk_vp_vote FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE,
    CONSTRAINT fk_vp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
