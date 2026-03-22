# Database Load Options

GigHive gives you a few ways to get media into the system and refresh the database.

## Easiest option for most users

Use the **GigHive iPhone app**.

For most end users, this is the simplest way to add music and video to GigHive. It is the most user-friendly path because users can upload directly from their phone without dealing with admin tools or destructive database reload steps.

- App Store: [GigHive - Upload Music, Video](https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513)
- Best for normal user uploads
- Does not require using the admin page database tools

## Admin options

The remaining options in `admin.php` are mainly for admins.

## Best admin option for larger imports

Use **Choose a Folder to Scan & Refresh the Database**.

This is the most current database-loading workflow in the admin page.

What it does:

- scans a folder you choose
- finds supported media files
- computes hashes for those files
- rebuilds the media tables with the imported metadata

This is the preferred admin option when you want to load a real media collection into GigHive.

## File uploads without rebuilding the database

Use **Upload Files Individually** when you want to send media into GigHive one file at a time from the admin tools.

## Starting over with the sample data removed

Use **Clear Sample Media** if you want to remove the demo content that ships with GigHive.

This clears the media tables but keeps the users table.

## CSV-based options

GigHive still includes CSV-based reload options in `admin.php`, but these should be treated as **legacy** paths.

There are two CSV choices:

- a single CSV reload
- a normalized reload using `sessions.csv` and `session_files.csv`

Both are destructive and rebuild the media tables.

If you are starting fresh, prefer the folder-scan workflow instead of the older single-CSV path.

If you already have normalized CSV exports that match GigHive’s expected format, the two-file normalized CSV option is the better CSV choice.

## Important warning

Several admin-page database actions are **destructive**.

Before using them:

- make sure you want to replace existing media metadata
- keep a backup if the current database contents matter
- remember that these tools are for admin use only
