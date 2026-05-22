# Refactor: Upload Folder — User Messaging + Server-Side Monotonic State

## Background

During large uploads, if the browser tab is refreshed, the VM resets, or "Restart Upload" is
clicked after a stall, the pending file count can appear much higher than expected. Example:
upload appeared to have 77 files left; after "Restart Upload" showed 175 pending.

This is caused by two related issues documented below.

---

## Issue 1: No User Explanation When Pending Count Is Inflated on Resume

### Problem

When `import_manifest_upload_start.php` returns the file list on resume, it reflects the
on-disk `upload_status.json` state — which may include files that were completed in memory
but never flushed to disk before the interruption. The UI shows the inflated pending count
with no explanation, which is confusing.

### Fix (browser-side — minimal)

In `sectionStartUpload()` in `admin_database_load_import_media_from_folder.php`, after
`files = d.files` is assigned (~line 735), add a trace note before `renderUploadRows`:

```js
const resumedPending = files.filter(f => f.state === 'pending').length;
const alreadyDone = files.filter(f =>
    ['db_done','thumbnail_done','already_present'].includes(f.state)).length;
if (alreadyDone > 0 && resumedPending > 0) {
    pushClientTrace(id, {
        endpoint: 'admin_database_load_import_media_from_folder.php',
        phase: 'resume_pending_note',
        message: resumedPending + ' file(s) marked pending — TUS will resume partial uploads '
            + 'from their last byte offset. No full re-upload needed.',
    });
}
```

This surfaces in the upload log and sets user expectation without changing any behavior.

---

## Issue 2: Server-Side State Is Not Monotonic on Resume

### Problem

`upload_status.json` on disk retains the state at the time of last write. If a file
reached `thumbnail_done` or `db_done` in memory but the server or browser was interrupted
before the JSON was written with that state, it remains `pending` on disk.

When "Restart Upload" calls `import_manifest_upload_start.php`, it serves the stale
`pending` state directly from the file — so the browser re-queues files that are actually
already complete. TUS handles this gracefully (resumes from the last byte offset or skips if
already complete), but the user sees an inflated pending count and a confusing status.

### Fix (server-side — `import_manifest_upload_start.php`)

Before returning the file list, for all files with `state=pending`, bulk-query the DB
by `checksum_sha256` to find which are already present as assets:

```php
// Collect checksums of all pending files
$pendingChecksums = array_column(
    array_filter($files, fn($f) => ($f['state'] ?? '') === 'pending'),
    'checksum_sha256'
);

if (!empty($pendingChecksums)) {
    $placeholders = implode(',', array_fill(0, count($pendingChecksums), '?'));
    $existing = $db->fetchAll(
        "SELECT checksum_sha256 FROM assets WHERE checksum_sha256 IN ($placeholders)",
        $pendingChecksums
    );
    $existingSet = array_flip(array_column($existing, 'checksum_sha256'));

    foreach ($files as &$f) {
        if (($f['state'] ?? '') === 'pending'
            && isset($existingSet[$f['checksum_sha256']])) {
            $f['state'] = 'already_present';
        }
    }
    unset($f);
}
```

This ensures that `pending` on disk is always reconciled against DB reality at resume time.
Files already in the DB are promoted to `already_present` before the browser ever sees them,
eliminating the inflated count entirely.

### Scope

- One bulk `WHERE checksum_sha256 IN (...)` query — no N+1 risk.
- Only affects the resume path; normal first-run uploads are unaffected.
- Does not modify `upload_status.json` on disk (read-time reconciliation only).

---

## Related Docs

- `docs/process_upload_statuses_definition.md` — full upload state definitions
- `docs/guide_ai_worker_tagging.md` — what happens after upload completes
