-- 045: rename 'resident' role to 'owner' + per-association configurable permissions

-- Step 1: widen the ENUM temporarily to hold both values during migration
ALTER TABLE users MODIFY COLUMN role
    ENUM('super_admin','board_admin','board_member','property_manager','owner','resident','renter','staff')
    NOT NULL DEFAULT 'owner';

-- Step 2: migrate all existing 'resident' rows to 'owner'
UPDATE users SET role = 'owner' WHERE role = 'resident';

-- Step 3: drop 'resident' from the ENUM now that no rows use it
ALTER TABLE users MODIFY COLUMN role
    ENUM('super_admin','board_admin','board_member','property_manager','owner','renter','staff')
    NOT NULL DEFAULT 'owner';

-- Per-association configurable permission overrides.
-- When a row exists here it overrides the hardcoded PHP default.
-- When no row exists the PHP default applies.
CREATE TABLE IF NOT EXISTS association_permissions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    association_id INT         NOT NULL,
    permission_key VARCHAR(80) NOT NULL,
    min_role       VARCHAR(50) NOT NULL,
    updated_by     INT         NULL,
    updated_at     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_assoc_perm (association_id, permission_key),
    CONSTRAINT fk_perms_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_perms_user  FOREIGN KEY (updated_by)     REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB;
