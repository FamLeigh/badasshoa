-- 030: Work Orders + per-unit / per-member document scoping
--
-- Additive migration (per the project's non-destructive prod rule):
--   * New table work_orders
--   * New table work_order_notes (status-change + free-text timeline)
--   * documents gains nullable unit_id + user_id (both NULL = association-wide,
--     today's behavior; either set narrows the document to that scope)
--
-- Work orders are admin-only operational tickets (board / property manager
-- create + manage; members don't see them). Source linkage to concerns is
-- optional via source_concern_id so a complaint about a leaky faucet can be
-- promoted to a tracked WO with one click.

CREATE TABLE IF NOT EXISTS work_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    association_id       INT  NOT NULL,
    title                VARCHAR(255) NOT NULL,
    body                 TEXT NULL,
    status               ENUM('open','in_progress','blocked','completed','closed') NOT NULL DEFAULT 'open',
    priority             ENUM('low','normal','high','urgent')                       NOT NULL DEFAULT 'normal',
    location_id          INT  NULL,
    unit_id              INT  NULL,
    assigned_user_id     INT  NULL,
    contractor_contact_id INT NULL,
    cost_estimate        DECIMAL(10,2) NULL,
    cost_actual          DECIMAL(10,2) NULL,
    source_concern_id    INT  NULL,
    due_date             DATE NULL,
    opened_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at            TIMESTAMP NULL,
    created_by           INT  NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assoc_status   (association_id, status),
    KEY idx_assoc_priority (association_id, priority),
    KEY idx_wo_unit        (unit_id),
    KEY idx_wo_concern     (source_concern_id),
    CONSTRAINT fk_wo_assoc      FOREIGN KEY (association_id)        REFERENCES associations(id)          ON DELETE CASCADE,
    CONSTRAINT fk_wo_location   FOREIGN KEY (location_id)           REFERENCES locations(id)             ON DELETE SET NULL,
    CONSTRAINT fk_wo_unit       FOREIGN KEY (unit_id)               REFERENCES units(id)                 ON DELETE SET NULL,
    CONSTRAINT fk_wo_assignee   FOREIGN KEY (assigned_user_id)      REFERENCES users(id)                 ON DELETE SET NULL,
    CONSTRAINT fk_wo_contractor FOREIGN KEY (contractor_contact_id) REFERENCES association_contacts(id)  ON DELETE SET NULL,
    CONSTRAINT fk_wo_concern    FOREIGN KEY (source_concern_id)     REFERENCES concerns(id)              ON DELETE SET NULL,
    CONSTRAINT fk_wo_creator    FOREIGN KEY (created_by)            REFERENCES users(id)                 ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_order_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    work_order_id   INT  NOT NULL,
    author_id       INT  NULL,
    body            TEXT NULL,
    is_status_change TINYINT(1) NOT NULL DEFAULT 0,
    new_status      VARCHAR(20) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_won_wo (work_order_id, created_at),
    CONSTRAINT fk_won_wo     FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_won_author FOREIGN KEY (author_id)     REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- Document scoping. documents.unit_id already shipped in migration 018; this
-- adds the companion user_id for member-scoped documents (e.g. a single
-- member's lease, a board appointment letter to a specific person).
--   * NULL + NULL  = association-wide (default)
--   * unit_id set  = surfaces on the unit detail page
--   * user_id set  = surfaces on that member's profile
--   * both can be set (e.g. a lease tied to unit + tenant)
ALTER TABLE documents
    ADD COLUMN user_id INT NULL AFTER unit_id,
    ADD KEY idx_doc_user (user_id),
    ADD CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
