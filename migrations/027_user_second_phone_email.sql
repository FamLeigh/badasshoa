-- Optional second phone + second email on user records. Common for
-- couples or homeowners with both a cell and a landline / personal +
-- work email. Both nullable; existing single phone/email unchanged.

USE badassHOA;

ALTER TABLE users
    ADD COLUMN phone2 VARCHAR(30)  NULL AFTER phone,
    ADD COLUMN email2 VARCHAR(255) NULL AFTER email;
