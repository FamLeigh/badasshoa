-- Work-order attachments: images and PDFs attached to a work order.
-- Matches the same shape as arc_request_attachments (migration 034).

CREATE TABLE IF NOT EXISTS work_order_attachments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    work_order_id   INT NOT NULL,
    uploaded_by     INT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_name       VARCHAR(255) NULL,
    file_type       VARCHAR(120) NULL,
    file_size       INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wo_att (work_order_id),
    CONSTRAINT fk_wo_att_order    FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_wo_att_uploader FOREIGN KEY (uploaded_by)  REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB;
