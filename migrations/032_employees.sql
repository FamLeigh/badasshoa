-- 032: HOA employees / contractors / volunteers
--
-- Orthogonal to users.role and unit_occupants. A unit owner can have an
-- active employees row that says "Maintenance, $25/hr, active since
-- 2024-01" without changing their role or owner status. Multiple rows per
-- user are allowed so the table doubles as employment history.
--
-- Additive — existing data untouched.

CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    association_id  INT  NOT NULL,
    user_id         INT  NOT NULL,
    job_title       VARCHAR(120) NOT NULL,
    employment_type ENUM('employee','contractor','volunteer') NOT NULL DEFAULT 'employee',
    pay_type        ENUM('hourly','salary','flat','none')     NOT NULL DEFAULT 'hourly',
    hourly_rate     DECIMAL(8,2)  NULL,
    salary          DECIMAL(10,2) NULL,
    flat_amount     DECIMAL(10,2) NULL,
    start_date      DATE NULL,
    end_date        DATE NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes           TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assoc_status (association_id, status),
    KEY idx_user         (user_id),
    CONSTRAINT fk_emp_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_user  FOREIGN KEY (user_id)        REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB;
