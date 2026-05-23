-- Track signed copies of uploaded PDF documents.
-- Each record points to the signed PDF file saved in storage and
-- records who signed, which page they placed their sig on, and when.
CREATE TABLE IF NOT EXISTS document_signatures (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id       INT UNSIGNED NOT NULL,
    document_id          INT UNSIGNED NOT NULL,
    signer_user_id       INT UNSIGNED NOT NULL,
    signed_file_path     VARCHAR(500) NOT NULL,
    signature_image_path VARCHAR(500) NULL,
    page_num             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ds_assoc   (association_id),
    INDEX idx_ds_doc     (document_id),
    INDEX idx_ds_signer  (signer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
