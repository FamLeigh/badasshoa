-- Migration 068: Real estate and rental agent links on units
-- Adds real_estate_agent kind to association_contacts and two nullable FK columns on units.

ALTER TABLE association_contacts
  MODIFY COLUMN kind ENUM('emergency','non_emergency','contractor','utility','rental_agent','real_estate_agent','other')
    NOT NULL DEFAULT 'other';

ALTER TABLE units
  ADD COLUMN realtor_contact_id     INT NULL AFTER notes,
  ADD COLUMN rental_agent_contact_id INT NULL AFTER realtor_contact_id;
