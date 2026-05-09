-- Local development seed.
-- Demo association + super admin + demo board user, all with password "changeme!".
-- DO NOT run on production. Production uses 003_seed_production.sql instead.

USE badassHOA;

INSERT INTO associations (id, name, subdomain, address, unit_count, plan, status)
VALUES (1, 'Demo Condos', 'demo', '123 Demo Lane, Demoville', 48, 'starter', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- bcrypt hash of "changeme!" (cost 12). Generated via password_hash().
INSERT INTO users (id, association_id, first_name, last_name, email, password_hash, role, status)
VALUES (
  1, NULL, 'Super', 'Admin', 'admin@badasshoa.com',
  '$2y$12$1IJhj9FNQZc0.fmBSIdxfO3scJGyD6cmUQ82Kzp5RgOeURFFG02Wi',
  'super_admin', 'active'
)
ON DUPLICATE KEY UPDATE email=VALUES(email);

INSERT INTO users (id, association_id, first_name, last_name, email, password_hash, role, unit_number, status)
VALUES (
  2, 1, 'Demo', 'Board', 'board@demo.badasshoa.com',
  '$2y$12$1IJhj9FNQZc0.fmBSIdxfO3scJGyD6cmUQ82Kzp5RgOeURFFG02Wi',
  'board_admin', '101', 'active'
)
ON DUPLICATE KEY UPDATE email=VALUES(email);
