-- Recurring events. Two new optional columns on events; series are stored as a
-- single row with a recurrence rule, occurrences are computed at render time.
-- Additive only — existing single events keep recurrence_type='none' and behave
-- exactly as before.

USE badassHOA;

ALTER TABLE events
    ADD COLUMN recurrence_type ENUM('none','daily','weekly','biweekly','monthly') NOT NULL DEFAULT 'none' AFTER audience,
    ADD COLUMN recurrence_until DATE NULL AFTER recurrence_type;
