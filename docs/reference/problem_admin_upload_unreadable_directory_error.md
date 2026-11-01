# Problem: "Hashing error: The requested file could not be read" in Media Import

## Symptom

In the admin media import page (`/admin/admin_database_load_import_media_from_folder.php`),
after selecting a folder and clicking **Scan & Submit**, the status banner shows:

> Hashing error: The requested file could not be read, typically due to permission
> problems that have occurred after a reference to a file was acquired.

The folder is enumerated correctly (file count and total size are shown), but hashing
fails immediately for every file.

## Root Cause

The SHA-256 hashing is performed **entirely client-side** in a browser Web Worker using
the browser's File System Access API (`file.slice().arrayBuffer()`). No server or Docker
container is involved in this step.

Chrome (and Chromium-based browsers) throw a `NotReadableError` (`DOMException`) when a
Web Worker attempts to read file bytes from certain folder types:

- **OneDrive, Google Drive, Dropbox, or any other cloud-synced folder** — the sync agent
  may hold file locks or the browser sandbox cannot access the cloud-provider virtual
  filesystem layer
- Network shares / UNC paths (`\\server\share\...`)
- Certain Windows "protected shell folders" (Desktop, Documents) on some Chrome versions

The error fires at the content-read stage, not at the directory-listing stage, which is
why file count and size are displayed correctly before the error appears.

## Fix Applied

`admin_database_load_import_media_from_folder.php` (the catch block in `sectionScan`)
was updated to detect `NotReadableError` / "could not be read" in the error message and
append a targeted hint to the error banner:

> **Tip:** If your files are in a OneDrive, Google Drive, or other cloud-synced folder,
> copy them to a plain local folder (e.g. Downloads) and try again.

## Workaround for Users

Copy the media files to a plain local folder that is **not** managed by a cloud sync
client (e.g. `C:\Music\` or `C:\Downloads\`) and select that folder instead.

## Confirmed Non-Causes

- Docker Desktop file sharing settings — irrelevant; hashing never touches the container
- Container file permissions — irrelevant for the same reason
- Apache / PHP-FPM logs — will show no errors because the failure is browser-side
- Windows Defender — possible secondary cause for transient failures, but not the primary
  cause when the folder is a cloud-sync location
