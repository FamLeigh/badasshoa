ALTER TABLE associations
    ADD COLUMN tv_mode ENUM('columns','ticker') NOT NULL DEFAULT 'columns';
