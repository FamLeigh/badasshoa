-- Shared reference table for state statutes (not tenant-scoped).
-- Populated by super-admin CSV import at /admin/legal.php.
-- FULLTEXT index covers section_title + keywords + summary only
-- (full_text is LONGTEXT and excluded from the index to keep it manageable).

CREATE TABLE IF NOT EXISTS statutes (
    id             VARCHAR(40)   NOT NULL,
    jurisdiction   VARCHAR(60)   NOT NULL DEFAULT '',
    state_code     CHAR(2)       NOT NULL,
    chapter        VARCHAR(10)   NOT NULL,
    chapter_title  VARCHAR(200)  NOT NULL DEFAULT '',
    applies_to     VARCHAR(30)   NOT NULL DEFAULT '',
    part           VARCHAR(10)            DEFAULT NULL,
    part_title     VARCHAR(200)           DEFAULT NULL,
    section        VARCHAR(30)   NOT NULL,
    section_title  VARCHAR(300)  NOT NULL DEFAULT '',
    category       VARCHAR(100)           DEFAULT NULL,
    subcategory    VARCHAR(100)           DEFAULT NULL,
    keywords       TEXT                   DEFAULT NULL,
    summary        TEXT          NOT NULL DEFAULT '',
    full_text      LONGTEXT               DEFAULT NULL,
    has_full_text  TINYINT(1)    NOT NULL DEFAULT 0,
    effective_date VARCHAR(20)            DEFAULT NULL,
    source_url     VARCHAR(500)           DEFAULT NULL,
    last_scraped   DATE                   DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_statute_section (state_code, section),
    FULLTEXT KEY ft_statutes (section_title, keywords, summary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
