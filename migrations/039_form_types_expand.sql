-- 039: Expand form_type ENUM with the rest of the form library
--
-- Adds 8 new form types alongside the original 5 (plus 'other'):
--   maintenance_request    — leaks, electrical, appliance, HVAC, common-area
--   pet_registration       — annual / on-arrival pet roster
--   vehicle_registration   — resident's own permanent vehicle (not a temp pass)
--   contractor_notice      — heads-up that a contractor will be in unit X
--   amenity_reservation    — book the clubhouse / pool deck / BBQ pit
--   hurricane_checklist    — owner confirms they prepped before a storm
--   emergency_contact      — who to call if owner not reachable
--   estoppel_request       — title companies request when a unit is sold
--
-- Additive — existing enum values preserved.

ALTER TABLE form_submissions
    MODIFY COLUMN form_type ENUM(
        'guest_registration',
        'parking_pass',
        'move_in',
        'move_out',
        'key_request',
        'maintenance_request',
        'pet_registration',
        'vehicle_registration',
        'contractor_notice',
        'amenity_reservation',
        'hurricane_checklist',
        'emergency_contact',
        'estoppel_request',
        'other'
    ) NOT NULL DEFAULT 'guest_registration';
