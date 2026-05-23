-- Meeting minutes: store the path and MIME type of an uploaded sign-in sheet.
ALTER TABLE meeting_minutes
    ADD COLUMN signin_sheet_path VARCHAR(500) NULL AFTER attendee_user_ids,
    ADD COLUMN signin_sheet_type VARCHAR(100) NULL AFTER signin_sheet_path;
