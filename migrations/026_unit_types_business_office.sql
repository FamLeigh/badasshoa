-- Add 'business' and 'main_office' to the units.type ENUM so mixed-use
-- buildings can register commercial tenants and the property's own office
-- alongside residential units. Additive — existing values unchanged.

USE badassHOA;

ALTER TABLE units
    MODIFY COLUMN type ENUM('condo','townhouse','single_family','apartment','business','main_office','other')
                  DEFAULT 'condo';
