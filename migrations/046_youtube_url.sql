ALTER TABLE associations
    ADD COLUMN youtube_url VARCHAR(500) NULL DEFAULT NULL AFTER nextdoor_url;
