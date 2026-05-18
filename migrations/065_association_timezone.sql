-- Migration 065: Add timezone to associations
ALTER TABLE associations
  ADD COLUMN timezone VARCHAR(50) NOT NULL DEFAULT 'America/New_York' AFTER country;
