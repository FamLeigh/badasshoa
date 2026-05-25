-- Open vs private bookings + link a booking to the auto-created community event.
-- Additive: existing rows default to 'private' (the safer of the two — no one
-- gets unexpectedly broadcast to the whole building).

USE badassHOA;

ALTER TABLE amenity_bookings
    ADD COLUMN event_kind ENUM('open','private') NOT NULL DEFAULT 'private' AFTER attendee_count,
    ADD COLUMN event_id   INT UNSIGNED NULL AFTER event_kind,
    ADD KEY idx_ab_event (event_id);
