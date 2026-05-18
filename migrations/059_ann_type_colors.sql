-- Announcement tag colour overrides per association (optional; NULL = use app defaults)
ALTER TABLE associations ADD COLUMN ann_type_colors JSON NULL DEFAULT NULL;
