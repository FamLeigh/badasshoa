-- Add birth_notice and death_notice to the announcement type enum
ALTER TABLE announcements
    MODIFY COLUMN type
    ENUM('general','emergency','event','maintenance','beautification','birth_notice','death_notice')
    DEFAULT 'general';
