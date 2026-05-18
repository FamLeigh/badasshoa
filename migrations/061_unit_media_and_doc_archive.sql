-- 061: Unit media gallery + document archiving
--
-- 1. documents.archived_at — soft-delete / archive for any document.
--    NULL = active (default). Set to NOW() to archive; NULL to restore.
--
-- 2. unit_media — image gallery per unit, separate from the documents table.
--    Floor plan images, interior photos, renovation before/after, etc.
--    Served through file.php?type=unit_media (auth + occupant check).

ALTER TABLE documents
    ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS unit_media (
    id              INT          NOT NULL AUTO_INCREMENT,
    association_id  INT          NOT NULL,
    unit_id         INT          NOT NULL,
    title           VARCHAR(255) NOT NULL DEFAULT '',
    file_path       VARCHAR(500) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL DEFAULT 'image/jpeg',
    uploaded_by     INT          NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_um_assoc (association_id),
    KEY idx_um_unit  (unit_id),
    CONSTRAINT fk_um_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_um_unit  FOREIGN KEY (unit_id)        REFERENCES units(id)        ON DELETE CASCADE,
    CONSTRAINT fk_um_user  FOREIGN KEY (uploaded_by)    REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
