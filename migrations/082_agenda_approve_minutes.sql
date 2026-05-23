-- Add 'approve_minutes' category and backfill any existing meetings that are missing it.
ALTER TABLE agenda_items
    MODIFY COLUMN category ENUM(
        'call_to_order','proof_of_notice','certify_quorum','approve_minutes',
        'officers_report','old_business','new_business',
        'motion_to_adjourn','public_comments','custom'
    ) NOT NULL DEFAULT 'custom';

-- Backfill: insert into any board meeting that doesn't already have this item.
INSERT INTO agenda_items (meeting_id, association_id, sort_order, category, title, status, entered_by_user_id)
SELECT m.id, m.association_id, 40, 'approve_minutes', 'Approve Minutes from Last Meeting', 'approved',
       (SELECT id FROM users WHERE association_id = m.association_id AND role IN ('board_admin','property_manager') ORDER BY id LIMIT 1)
  FROM board_meetings m
 WHERE NOT EXISTS (
     SELECT 1 FROM agenda_items a WHERE a.meeting_id = m.id AND a.category = 'approve_minutes'
 );
