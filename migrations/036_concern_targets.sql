-- 036: Structured targets on concerns
--
-- Lets a complaint / compliment / suggestion say WHO or WHAT it's about,
-- so the board sees "complaint about John in 412 (cited rule 3.4)" instead
-- of unstructured text in the body.
--
-- Three additions:
--   * concerns.target_user_id  — optional FK to users
--   * concerns.target_unit_id  — optional FK to units
--   * concern_rule_citations    — many-to-many pivot to rules
--
-- Privacy note: visibility is enforced in concerns.php — the target user
-- never sees they were named (they see no concerns about them unless they
-- ARE the submitter). Board + submitter see the full target info.
--
-- Additive — existing data untouched.

ALTER TABLE concerns
    ADD COLUMN target_user_id INT NULL AFTER submitter_user_id,
    ADD COLUMN target_unit_id INT NULL AFTER target_user_id,
    ADD KEY idx_target_user (target_user_id),
    ADD KEY idx_target_unit (target_unit_id),
    ADD CONSTRAINT fk_conc_target_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_conc_target_unit FOREIGN KEY (target_unit_id) REFERENCES units(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS concern_rule_citations (
    concern_id INT NOT NULL,
    rule_id    INT NOT NULL,
    PRIMARY KEY (concern_id, rule_id),
    KEY idx_rule (rule_id),
    CONSTRAINT fk_crc_concern FOREIGN KEY (concern_id) REFERENCES concerns(id) ON DELETE CASCADE,
    CONSTRAINT fk_crc_rule    FOREIGN KEY (rule_id)    REFERENCES rules(id)    ON DELETE CASCADE
) ENGINE=InnoDB;
