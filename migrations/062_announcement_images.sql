-- 062: Optional image attachment on announcements.
--      image_path mirrors the pattern used by events.image_path.
--      Served through /announcement-image.php (public, UUID-based filenames).

ALTER TABLE announcements
    ADD COLUMN image_path VARCHAR(500) NULL DEFAULT NULL;
