ALTER TABLE associations
    ADD COLUMN tv_dark TINYINT NOT NULL DEFAULT 1
        COMMENT '1 = dark theme (default), 0 = light theme';
