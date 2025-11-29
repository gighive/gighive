-- Truncate all media-related tables, preserving the users table
-- This allows users to clear out demo content and add their own media
-- Execute order matters due to foreign key constraints

SET FOREIGN_KEY_CHECKS = 0;

-- Junction tables first (no dependencies on other tables)
TRUNCATE TABLE session_musicians;
TRUNCATE TABLE session_songs;
TRUNCATE TABLE song_files;

-- Core media tables
TRUNCATE TABLE files;
TRUNCATE TABLE songs;
TRUNCATE TABLE sessions;

-- Reference/lookup tables
TRUNCATE TABLE musicians;
TRUNCATE TABLE genres;
TRUNCATE TABLE styles;

SET FOREIGN_KEY_CHECKS = 1;

-- Note: The 'users' table is intentionally NOT truncated
-- This preserves authentication data while clearing all media content
