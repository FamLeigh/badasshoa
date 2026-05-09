-- BadassHOA schema. Idempotent — safe to re-run.
-- Local: mysql --socket=/Applications/MAMP/tmp/mysql/mysql.sock -u root -proot < migrations/001_schema.sql
-- Production (Hostinger): import this via phpMyAdmin into your already-created database.
--                         (Don't include the CREATE DATABASE / USE lines below; remove or comment them.)

CREATE DATABASE IF NOT EXISTS badassHOA CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE badassHOA;

-- ============================================================================
-- Core tenant + user tables
-- ============================================================================

CREATE TABLE IF NOT EXISTS associations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  subdomain VARCHAR(100) UNIQUE NOT NULL,
  address TEXT,
  unit_count INT DEFAULT 0,
  logo_path VARCHAR(500),
  primary_color VARCHAR(7) DEFAULT '#0f1f3d',
  plan ENUM('starter','growth','professional','enterprise') DEFAULT 'starter',
  status ENUM('active','inactive','trial') DEFAULT 'trial',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT,
  first_name VARCHAR(100),
  last_name VARCHAR(100),
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','board_admin','board_member','property_manager','resident','renter') DEFAULT 'resident',
  unit_number VARCHAR(20),
  phone VARCHAR(30),
  is_owner TINYINT(1) DEFAULT 1,
  status ENUM('active','inactive','pending') DEFAULT 'pending',
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc (association_id),
  KEY idx_role (role),
  CONSTRAINT fk_users_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================================
-- Content tables
-- ============================================================================

CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  category VARCHAR(100),
  file_path VARCHAR(500),
  file_type VARCHAR(50),
  access_level ENUM('public','members_only','board_only') DEFAULT 'members_only',
  uploaded_by INT,
  version VARCHAR(20) DEFAULT '1.0',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc_category (association_id, category),
  KEY idx_access (access_level),
  CONSTRAINT fk_docs_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_docs_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  category VARCHAR(100),
  source ENUM('bylaw','board_rule','policy') DEFAULT 'board_rule',
  rule_number VARCHAR(50),
  effective_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc (association_id),
  FULLTEXT KEY search_rules (title, body),
  CONSTRAINT fk_rules_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  author_id INT,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  type ENUM('general','emergency','event','maintenance') DEFAULT 'general',
  audience ENUM('all','owners','renters','board') DEFAULT 'all',
  send_email TINYINT(1) DEFAULT 0,
  published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc_pub (association_id, published_at),
  CONSTRAINT fk_ann_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ann_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS media (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  uploaded_by INT,
  file_path VARCHAR(500) NOT NULL,
  file_name VARCHAR(255),
  file_type VARCHAR(50),
  caption TEXT,
  category VARCHAR(100),
  visibility ENUM('public','private') DEFAULT 'private',
  linked_type ENUM('general','work_order','violation','announcement') DEFAULT 'general',
  linked_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assoc_vis (association_id, visibility),
  CONSTRAINT fk_media_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS signups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_name VARCHAR(255),
  contact_name VARCHAR(255),
  contact_email VARCHAR(255),
  contact_phone VARCHAR(30),
  unit_count INT,
  plan_selected VARCHAR(50),
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB;

-- ============================================================================
-- Safety tables
-- ============================================================================

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT,
  association_id INT,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(50),
  target_id INT,
  ip_address VARCHAR(45),
  metadata JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_actor (actor_user_id),
  KEY idx_assoc_time (association_id, created_at),
  KEY idx_action (action)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255),
  ip_address VARCHAR(45) NOT NULL,
  succeeded TINYINT(1) DEFAULT 0,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_time (ip_address, attempted_at),
  KEY idx_email_time (email, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) PRIMARY KEY,
  user_id INT,
  ip_address VARCHAR(45),
  user_agent VARCHAR(500),
  payload TEXT,
  last_activity INT NOT NULL,
  KEY idx_user (user_id),
  KEY idx_activity (last_activity),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
