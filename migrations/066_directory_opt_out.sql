ALTER TABLE users
    ADD COLUMN hide_from_directory TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_public_landing;
