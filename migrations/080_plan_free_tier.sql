-- Add 'free' as a valid plan tier so super admins can grant free access.
ALTER TABLE associations
    MODIFY COLUMN plan ENUM('free','starter','growth','professional','enterprise') DEFAULT 'starter';
