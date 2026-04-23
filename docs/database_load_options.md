# Database Load Options

GigHive gives you a few ways to get media into the system and refresh the database.

## Easiest option for most users

Use the **GigHive iPhone app**.

For most end users, this is the simplest way to add music and video to GigHive. It is the most user-friendly path because users can upload directly from their phone without dealing with admin tools or destructive database reload steps.

- App Store: [GigHive - Upload Music, Video](https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513)
- Best for normal user uploads
- Does not require using the admin page database tools

## Admin options

The remaining options are in dedicated admin import pages and are mainly for admins.

## Folder-based import (`admin_database_load_import_media_from_folder.php`)

This page has two folder-based sections plus a single-file fallback.

All folder-based imports are a **two-step process**:
- **Step 1:** Select a folder. The browser hashes all supported media files and submits metadata to the database.
- **Step 2:** Upload the actual media files to the server.

Both sections also have a **Previous Jobs (Recovery)** panel for retrying or resuming an interrupted import.

### Section A: Reload Database from Folder (destructive)

Truncates and rebuilds all media tables from the selected folder.

- All existing sessions/songs/files/musicians are deleted before the import
- Use this to replace the entire media collection from scratch
- Requires confirmation before proceeding

### Section B: Add to Database from Folder (non-destructive)

Adds new files from the selected folder without deleting existing data.

- Duplicate checksums are skipped automatically
- Safe to run incrementally against an existing collection
- Use this when you want to add new content without disturbing what is already in the database

### Section C: Single File Upload

Opens the standard upload form (`/db/upload_form.php`) for uploading one file at a time from the browser.

## CSV-based import (`admin_database_load_import_csv.php`)

This page has two CSV-based reload options. Both are **destructive** and rebuild the media tables.

### Section A (Legacy): Single CSV reload

Upload a single CSV file to rebuild the media database.

Required headers: `t_title`, `d_date`, `d_merged_song_lists`, `f_singles`

Use this only if you have an older single-file CSV export from a legacy GigHive database.

### Section B (Normalized): Two-file CSV reload

Upload `sessions.csv` and `session_files.csv` to rebuild the media database.

- `sessions.csv` required headers: `session_key`, `t_title`, `d_date`
- `session_files.csv` required headers: `session_key`, `source_relpath`

If you have normalized CSV exports that match GigHive's expected format, this is the better CSV option.

## Important warning

Several admin-page database actions are **destructive**.

Before using them:

- make sure you want to replace existing media metadata
- keep a backup if the current database contents matter
- remember that these tools are for admin use only
