# Problem: Race Condition Between tusd Hook Write and Finalize Call

## What happened

When a user uploaded a file using the upload form (`/db/upload_form.php`), the first
attempt returned a `500 Server Error` with the message
`Upload not found or not finished yet`, even though the file had just finished uploading.
On a second fresh attempt the upload succeeded normally.

## The upload flow (simplified)

```
Browser                    Apache / PHP              tusd
   |                           |                       |
   |-- POST /files/ ---------->|-- proxy ------------->|  (create upload slot)
   |<-- 201 Created -----------|<-- 201 ---------------| 
   |                           |                       |
   |-- PATCH /files/{id} ----->|-- proxy ------------->|  (send file bytes)
   |<-- 204 No Content --------|<-- 204 ---------------| 
   |                           |                       |
   |                           |           tusd fires post-finish hook (ASYNC)
   |                           |           hook script writes {id}.json to disk
   |                           |           (this takes a few milliseconds)
   |                           |                       |
   |-- POST /api/uploads/finalize -->|                  |
   |   (JS calls this immediately    |                  |
   |    after receiving the 204)     |                  |
   |                           |                       |
   |                   PHP checks: does                 |
   |                   tus-hooks/{id}.json exist?       |
   |                   → NO (hook not done yet)         |
   |<-- 500 "not found" -------|                        |
   |                           |                        |
   |                   (moments later) hook writes file |
```

## Root cause

When tusd receives the final PATCH (the last chunk of the file), it does **two things at
the same time**:

1. Sends `204 No Content` back to the browser — "your upload is done".
2. **Asynchronously** spawns a subprocess to run the post-finish hook script, which
   writes a JSON file describing the completed upload to
   `/var/www/private/tus-hooks/uploads/{id}.json`.

"Asynchronously" means tusd does not wait for the hook script to finish before telling
the browser the upload is complete.

The browser's JavaScript (`tus-js-client`) receives the `204`, immediately fires its
`onSuccess` callback, and immediately calls `POST /api/uploads/finalize`.

PHP's `finalizeTusUpload()` then checks for the hook JSON file on disk. If the hook
script hasn't finished writing that file yet (a race of a few milliseconds), PHP sees
nothing there and throws a `RuntimeException` → HTTP 500.

## Evidence

From `hook-debug.log` and the Apache access log, both events share the **exact same
second** (`19:45:35Z`), confirming the race:

```
2026-04-21T19:45:35Z  hook=post-finish  parsed_id=e5d227db5e14b999d79dea0e3e475cad  mv_ok=1
[21/Apr/2026:15:45:35 -0400]  POST /api/uploads/finalize  500  D=4612
```

`D=4612` (4.6 ms) confirms PHP failed instantly — `is_file()` returned `false` before
the hook subprocess had time to land the file.

## The fix

Two complementary changes in
`src/Services/UploadService.php` → `finalizeTusUpload()`:

### 1. Better error message (immediate)

Instead of the generic `'Upload not found or not finished yet'`, PHP now checks whether
the raw upload data is already on disk (proving the race condition) and reports
accordingly:

- **Data file exists, hook file missing** → `"Upload data exists but hook output not yet
  written; retry in a moment."` (race condition)
- **Neither file exists** → `"No data or hook record found for upload_id=…"` (genuinely
  unknown ID)

### 2. Short retry/poll loop (eliminates the race)

Before giving up, PHP polls for the hook file up to ~2 seconds (10 attempts × 200 ms).
In practice the hook script finishes in well under 100 ms, so the very first or second
retry succeeds and the caller never sees an error at all.

## Why the second attempt succeeded

After the first 500 error the user navigated away, reloaded the upload form, and
re-uploaded the file from scratch. That created a brand-new upload ID
(`685f11a5dc0fc947bbc11e384a4d8692`). By the time the browser called finalize for the
new upload the hook script had already finished (no race on the second round because
the file was larger and took a full second to transfer, giving the hook plenty of time).

## Files changed

- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php` —
  improved error message and retry loop in `finalizeTusUpload()`
