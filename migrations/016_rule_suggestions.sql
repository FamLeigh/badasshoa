-- Member-suggested rules awaiting board review.
-- A suggestion is independent until approved — once approved, a real rules row
-- is created and resulting_rule_id links the two for audit. Rejected
-- suggestions stay in this table for history with status='rejected' and an
-- optional decision_note.

USE badassHOA;

CREATE TABLE IF NOT EXISTS rule_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id      INT  NOT NULL,
  suggester_user_id   INT  NULL,
  title               VARCHAR(255) NOT NULL,
  body                TEXT NOT NULL,
  source              ENUM('bylaw','board_rule','policy') NOT NULL DEFAULT 'board_rule',
  category            VARCHAR(100) NULL,
  suggested_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by_user_id INT  NULL,
  reviewed_at         TIMESTAMP NULL,
  decision_note       TEXT NULL,
  approval_date       DATE NULL,
  resulting_rule_id   INT  NULL,
  KEY idx_assoc_status (association_id, status),
  KEY idx_suggester    (suggester_user_id),
  CONSTRAINT fk_rsug_assoc    FOREIGN KEY (association_id)      REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsug_user     FOREIGN KEY (suggester_user_id)   REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_rsug_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_rsug_rule     FOREIGN KEY (resulting_rule_id)   REFERENCES rules(id)        ON DELETE SET NULL
) ENGINE=InnoDB;
