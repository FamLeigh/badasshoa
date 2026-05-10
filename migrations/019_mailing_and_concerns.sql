-- Mailing address on users + 'beautification' announcement type +
-- concerns (complaints / compliments / suggestions) with a comment thread.
-- All additive — nothing existing changes.

USE badassHOA;

-- 1. Mailing address on users (for absentee owners renting their unit)
ALTER TABLE users
    ADD COLUMN mailing_address      VARCHAR(255) NULL AFTER phone,
    ADD COLUMN mailing_city         VARCHAR(100) NULL AFTER mailing_address,
    ADD COLUMN mailing_state_region VARCHAR(100) NULL AFTER mailing_city,
    ADD COLUMN mailing_postal_code  VARCHAR(20)  NULL AFTER mailing_state_region,
    ADD COLUMN mailing_country      VARCHAR(2)   NULL AFTER mailing_postal_code;

-- 2. Add 'beautification' to announcements.type ENUM (purely additive)
ALTER TABLE announcements
    MODIFY COLUMN type ENUM('general','emergency','event','maintenance','beautification') DEFAULT 'general';

-- 3. Concerns: members file complaints / compliments / suggestions; board reviews & resolves
CREATE TABLE IF NOT EXISTS concerns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id      INT  NOT NULL,
  submitter_user_id   INT  NULL,
  type                ENUM('complaint','compliment','suggestion') NOT NULL DEFAULT 'complaint',
  category            VARCHAR(100) NULL,
  subject             VARCHAR(255) NOT NULL,
  body                TEXT NOT NULL,
  is_anonymous        TINYINT(1) NOT NULL DEFAULT 0,
  status              ENUM('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
  resolved_at         TIMESTAMP NULL,
  resolved_by_user_id INT NULL,
  resolution_summary  TEXT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_assoc_status (association_id, status),
  KEY idx_submitter    (submitter_user_id),
  CONSTRAINT fk_conc_assoc     FOREIGN KEY (association_id)      REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_conc_submitter FOREIGN KEY (submitter_user_id)   REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_conc_resolved  FOREIGN KEY (resolved_by_user_id) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Comments / discussion thread on each concern. is_internal=1 means
-- "board-only note, submitter can't see" — useful for the board to coordinate
-- privately before posting a public response.
CREATE TABLE IF NOT EXISTS concern_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  concern_id     INT  NOT NULL,
  author_user_id INT  NULL,
  body           TEXT NOT NULL,
  is_internal    TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
  KEY idx_concern (concern_id),
  CONSTRAINT fk_cc_concern FOREIGN KEY (concern_id)     REFERENCES concerns(id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_user    FOREIGN KEY (author_user_id) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;
