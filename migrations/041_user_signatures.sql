-- 041: Saved signatures (user-scoped reusable signature library)
--
-- A user can keep one or more signatures on file and reuse them on future
-- forms without re-typing/drawing/uploading. Strictly private — only the
-- owning user (or super_admin for emergency support) sees them.
--
-- When a signature is reused, the form_submission still gets its own copy
-- of the image embedded in signature_image_path (or typed name copied to
-- signature_typed_name) — the saved record is a convenience cache, not a
-- live reference. Deleting a saved signature does NOT invalidate prior
-- submissions, just makes it unavailable for future use.

CREATE TABLE IF NOT EXISTS user_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    kind          ENUM('typed','drawn','uploaded') NOT NULL,
    label         VARCHAR(80)  NULL,           -- optional nickname ("My signature", "Initials")
    typed_name    VARCHAR(255) NULL,           -- when kind = typed
    image_path    VARCHAR(500) NULL,           -- when kind = drawn / uploaded
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at  TIMESTAMP NULL,
    KEY idx_user (user_id),
    CONSTRAINT fk_usig_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
