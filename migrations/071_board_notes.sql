-- Migration 071: Board notes — internal board/management notes on units and members
CREATE TABLE board_notes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  association_id  INT          NOT NULL,
  author_user_id  INT          NOT NULL,
  unit_id         INT          NULL,   -- which unit the note is filed under
  subject_user_id INT          NULL,   -- which person (NULL = unit-level note)
  note_text       TEXT         NOT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bn_unit   (association_id, unit_id),
  INDEX idx_bn_member (association_id, subject_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
