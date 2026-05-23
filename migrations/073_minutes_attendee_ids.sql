-- Minutes: store board member attendees as a comma-separated list of user IDs.
-- The existing `attendees` text column becomes "additional attendees" (guests, etc.).
ALTER TABLE meeting_minutes
    ADD COLUMN attendee_user_ids TEXT NULL AFTER attendees;
