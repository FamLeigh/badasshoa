-- Track who is required to sign a specific document.
-- One row per (document, user) pair. fulfilled_at is set when they sign.
CREATE TABLE IF NOT EXISTS document_signature_requests (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id   INT UNSIGNED NOT NULL,
    document_id      INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    fulfilled_at     TIMESTAMP NULL DEFAULT NULL,
    fulfilled_sig_id INT UNSIGNED NULL DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_user  (document_id, user_id),
    INDEX idx_dsr_assoc (association_id),
    INDEX idx_dsr_doc   (document_id),
    INDEX idx_dsr_user  (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add audit columns to document_signatures so the certificate page
-- can show IP, browser, and a tamper-evident event token.
ALTER TABLE document_signatures
    ADD COLUMN signer_ip    VARCHAR(45)  NULL AFTER page_num,
    ADD COLUMN signer_agent VARCHAR(500) NULL AFTER signer_ip,
    ADD COLUMN sign_token   CHAR(64)     NULL COMMENT 'SHA-256 event token for the audit certificate' AFTER signer_agent;
