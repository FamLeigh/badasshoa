-- 040: Electronic signatures on form submissions
--
-- ESIGN Act (federal) + Florida UETA compliance footprint:
--   * Intent to sign        — signature_kind + signature_typed_name OR signature_image_path
--   * Consent to electronic — consent_given (checkbox required on submit)
--   * Association with rec  — payload_hash (SHA-256 of payload at sign time;
--                             tamper-evident — if the form is edited later
--                             the hash mismatches and the signature is flagged)
--   * Audit trail           — signed_at, signed_ip, signed_user_agent
--   * Identity              — submitter_user_id already on the row (signed-in user)
--
-- Per-association config (which form types require a signature) lives in code
-- (forms_requiring_signature() in includes/functions.php) — easier to evolve
-- than a DB-managed picklist. Additive — no existing data touched.

ALTER TABLE form_submissions
    ADD COLUMN signature_kind        ENUM('typed','drawn','uploaded') NULL AFTER notes,
    ADD COLUMN signature_typed_name  VARCHAR(255) NULL AFTER signature_kind,
    ADD COLUMN signature_image_path  VARCHAR(500) NULL AFTER signature_typed_name,
    ADD COLUMN consent_given         TINYINT(1)   NOT NULL DEFAULT 0 AFTER signature_image_path,
    ADD COLUMN signed_at             TIMESTAMP    NULL AFTER consent_given,
    ADD COLUMN signed_ip             VARCHAR(45)  NULL AFTER signed_at,
    ADD COLUMN signed_user_agent     VARCHAR(255) NULL AFTER signed_ip,
    ADD COLUMN payload_hash          CHAR(64)     NULL AFTER signed_user_agent,
    ADD KEY idx_signed (signed_at);
