-- Public-landing content fields. All optional; left NULL until the board fills them in.

USE badassHOA;

ALTER TABLE associations
    ADD COLUMN hero_image_path VARCHAR(500)        AFTER logo_path,
    ADD COLUMN about_text      TEXT                AFTER hero_image_path,
    ADD COLUMN amenities_text  TEXT                AFTER about_text,
    ADD COLUMN contact_email   VARCHAR(255)        AFTER amenities_text,
    ADD COLUMN contact_phone   VARCHAR(30)         AFTER contact_email;
