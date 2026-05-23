-- Broadcast enhancements: custom recipient selection + PDF attachment support.

ALTER TABLE broadcasts
    MODIFY COLUMN audience ENUM('all','owners','renters','board','custom') NOT NULL DEFAULT 'all',
    ADD COLUMN custom_user_ids MEDIUMTEXT NULL COMMENT 'JSON array of user IDs when audience=custom',
    ADD COLUMN attachment_path VARCHAR(500) NULL,
    ADD COLUMN attachment_name VARCHAR(255) NULL;
