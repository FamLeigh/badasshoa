-- Committees (e.g. "Rules Committee", "Beautification Committee").
-- Each committee belongs to one association. Members are users; one optional chair per committee.

USE badassHOA;

CREATE TABLE IF NOT EXISTS committees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc (association_id),
  CONSTRAINT fk_comm_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS committee_members (
  committee_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('chair','member') DEFAULT 'member',
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (committee_id, user_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_cm_comm FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
