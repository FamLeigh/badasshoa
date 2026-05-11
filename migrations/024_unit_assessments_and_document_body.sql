-- Annual HOA and garage assessment amounts on units + a body_html TEXT on
-- documents so the board can compose documents inside the app (not just
-- upload existing files). For composed documents, file_path stays NULL and
-- body_html holds the Quill-generated HTML.
-- All additive.

USE badassHOA;

ALTER TABLE units
    ADD COLUMN annual_hoa_assessment    DECIMAL(10,2) NULL AFTER ownership_percent,
    ADD COLUMN annual_garage_assessment DECIMAL(10,2) NULL AFTER annual_hoa_assessment;

ALTER TABLE documents
    ADD COLUMN body_html LONGTEXT NULL AFTER description;
