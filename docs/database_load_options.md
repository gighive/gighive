# Database Load Options

GigHive gives you several ways to add media or rebuild media data. Choose the option that matches how much review and control you want before ingest starts.

## Choose the workflow that matches your goal

### Option 1 — Quickest for most users: GigHive iPhone app

Use the **GigHive iPhone app**.

For most end users, this is the simplest way to add music and video to GigHive. It is the most user-friendly path because users can upload directly from their phone without using the admin pages.

- App Store: [GigHive - Upload Music, Video](https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513)
- Best for everyday uploads
- Does not require using the admin pages

### Option 2 — Best when you want to review first: Catalog Media (`admin_database_catalog_media_from_folder.php`)

Use Catalog Media when you want to scan one or more folders first, review what is there, and decide what should be uploaded before any hashing or media transfer starts.

Catalog Media is a lightweight, non-destructive staging workflow.

- Reads filenames, extensions, sizes, and modification times only
- Does not hash files during the scan step
- Does not upload files during the scan step
- Useful for large archives, NAS folders, multi-drive collections, and selective imports

#### Section A: Catalog Media (Reload)

Clears the current catalog staging area and scans the selected folder fresh.

- Wipes the existing catalog entries
- Use this when starting a new batch from scratch
- Best when you want to discard prior staging work and rebuild the review list

#### Section B: Add to Catalog

Adds another folder to the existing catalog without clearing what is already staged.

- Keeps existing catalog entries
- Useful for building a combined review set across multiple folders or drives
- Best when you want to accumulate choices before upload

#### What happens after cataloging

Catalog Media is the review-first path:

1. Scan one or more folders into the catalog
2. Review and edit entries in `db/database_catalog.php`
3. Mark the entries you want to upload
4. Promote the selected entries into the upload flow
5. Complete the normal browser upload process

### Option 3 — Best when you are ready to ingest immediately: Folder-based import (`admin_database_load_import_media_from_folder.php`)

Use this page when you already know which files you want and are ready to start hashing and uploading right away.

Unlike Catalog Media, this path is for immediate ingest work rather than a separate pre-upload inventory and selection stage.

This page has two folder-based sections plus a single-file fallback.

All folder-based imports are a **two-step process**:
- **Step 1:** Select a folder. The browser hashes all supported media files and submits metadata to the database.
- **Step 2:** Upload the actual media files to the server.

Both sections also have a **Previous Jobs (Recovery)** panel for retrying or resuming an interrupted import.

#### Section A: Reload Database from Folder

Rebuilds the media tables from the selected folder.

- All existing sessions/songs/files/musicians are deleted before the import
- Use this to replace the entire media collection from scratch
- Requires confirmation before proceeding

#### Section B: Add to Database from Folder

Adds new files from the selected folder without deleting existing data.

- Duplicate checksums are skipped automatically
- Safe to run incrementally against an existing collection
- Use this when you want to add new content without disturbing what is already in the database

#### Section C: Single File Upload

Opens the standard upload form (`/db/upload_form.php`) for uploading one file at a time from the browser.

### Option 4 — Advanced path: CSV-based import (`admin_database_load_import_csv.php`)

Use this when your media information already exists in CSV files and you want to rebuild the media database from those exports.

This page has two CSV-based reload options. Both replace the current media tables with the contents of the uploaded CSV export.

#### Section A: Single CSV reload

Upload one CSV file to rebuild the media database.

Required headers: `t_title`, `d_date`, `d_merged_song_lists`, `f_singles`

This is mainly for older GigHive CSV exports that store everything in one file.

#### Section B: Two-file CSV reload

Upload `sessions.csv` and `session_files.csv` to rebuild the media database.

- `sessions.csv` required headers: `session_key`, `t_title`, `d_date`
- `session_files.csv` required headers: `session_key`, `source_relpath`

Use this when your CSV export is already split into session metadata and per-file rows.

## Which option should I choose?

- If you just want the simplest end-user upload path, use the iPhone app
- If you want to inspect folders and choose files before upload, use Catalog Media
- If you already know you want to ingest a folder now, use Folder-based import
- If you need to rebuild from CSV exports, use the CSV-based import options

## Important note

Some admin page database actions replace existing media metadata.

Before using them:

- Make sure you want to replace the current media metadata
- GigHive stores backups automatically, but has a feature to save a backup locally for additional security if the current database contents matter
- Remember that these tools are intended for admin use
