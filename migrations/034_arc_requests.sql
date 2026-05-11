-- 034: Architectural Review Committee (ARC) requests
--
-- Owners submit a request to change something visible from the exterior
-- (paint color, satellite dish, deck, windows, landscaping changes…).
-- Board / ARC committee reviews, asks questions, and approves or denies.
--
-- Three new tables:
--   * arc_requests           — the request itself
--   * arc_request_comments   — threaded discussion between submitter + board
--   * arc_request_attachments— photos / plans / contractor docs
--
-- Members see only their own requests; managers see everything.
-- Additive — no existing data touched.

CREATE TABLE IF NOT EXISTS arc_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    association_id      INT NOT NULL,
    submitter_user_id   INT NULL,
    unit_id             INT NULL,
    title               VARCHAR(255) NOT NULL,
    category            ENUM('paint','structural','roof','windows','doors','landscaping','fencing','signage','satellite_antenna','solar','other') NOT NULL DEFAULT 'other',
    description         TEXT NOT NULL,
    location_details    TEXT NULL,
    requested_start     DATE NULL,
    requested_end       DATE NULL,
    contractor_name     VARCHAR(255) NULL,
    contractor_license  VARCHAR(120) NULL,
    estimated_cost      DECIMAL(10,2) NULL,
    status              ENUM('draft','submitted','under_review','approved','denied','withdrawn','completed') NOT NULL DEFAULT 'submitted',
    decision_note       TEXT NULL,
    decision_conditions TEXT NULL,
    decided_at          TIMESTAMP NULL,
    decided_by_user_id  INT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assoc_status (association_id, status),
    KEY idx_submitter    (submitter_user_id),
    KEY idx_unit         (unit_id),
    CONSTRAINT fk_arc_assoc     FOREIGN KEY (association_id)    REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_arc_submitter FOREIGN KEY (submitter_user_id) REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_arc_unit      FOREIGN KEY (unit_id)           REFERENCES units(id)        ON DELETE SET NULL,
    CONSTRAINT fk_arc_decider   FOREIGN KEY (decided_by_user_id) REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arc_request_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id      INT NOT NULL,
    author_user_id  INT NULL,
    body            TEXT NOT NULL,
    is_internal     TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_arc_request (request_id, created_at),
    CONSTRAINT fk_arc_cmt_request FOREIGN KEY (request_id)     REFERENCES arc_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_arc_cmt_author  FOREIGN KEY (author_user_id) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arc_request_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id     INT NOT NULL,
    uploaded_by    INT NULL,
    file_path      VARCHAR(500) NOT NULL,
    file_name      VARCHAR(255) NULL,
    file_type      VARCHAR(120) NULL,
    file_size      INT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_arc_request_att (request_id),
    CONSTRAINT fk_arc_att_request FOREIGN KEY (request_id)  REFERENCES arc_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_arc_att_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB;
