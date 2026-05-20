-- Migration 072: Add type and date to board_notes
ALTER TABLE board_notes
  ADD COLUMN note_type VARCHAR(20) NOT NULL DEFAULT 'general' AFTER note_text,
  ADD COLUMN note_date DATE NULL AFTER note_type;
