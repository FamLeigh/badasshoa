-- 029: Board office (President / VP / Secretary / Treasurer / Secretary-Treasurer / Director)
-- Display-only label for board members + property managers. Does NOT affect
-- permissions — those still flow from `role` (board_admin vs board_member).
-- One office per person; NULL means "no specific office on file".

ALTER TABLE users
    ADD COLUMN board_office ENUM(
        'president',
        'vice_president',
        'secretary',
        'treasurer',
        'secretary_treasurer',
        'director'
    ) NULL DEFAULT NULL AFTER role;
