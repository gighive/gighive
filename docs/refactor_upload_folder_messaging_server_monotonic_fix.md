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

**Insertion point:** in the resume branch, after the `$normalized > 0` disk-write block and
before the `gighive_manifest_append_upload_trace(...)` call. The resume branch variable is
`$existing['files']`; the file uses raw PDO via `Database::createFromEnv()` — not a `$db`
helper. A new connection is required because `$pdo` is only initialised in the first-run
branch.

```php
// DB reconciliation: promote stale pending entries already in the DB to already_present.
$reconciled = 0;
$pendingChecksums = array_values(array_column(
    array_filter($existing['files'], fn($f) => is_array($f) && ($f['state'] ?? '') === 'pending'),
    'checksum_sha256'
));
if (!empty($pendingChecksums)) {
    $pdoResume = Database::createFromEnv();
    $placeholders = implode(',', array_fill(0, count($pendingChecksums), '?'));
    $stmtResume = $pdoResume->prepare(
        "SELECT checksum_sha256 FROM assets WHERE checksum_sha256 IN ($placeholders)"
    );
    $stmtResume->execute($pendingChecksums);
    $inDbSet = array_flip(array_column($stmtResume->fetchAll(PDO::FETCH_ASSOC), 'checksum_sha256'));
    foreach ($existing['files'] as &$f) {
        if (is_array($f) && ($f['state'] ?? '') === 'pending'
                && !empty($f['checksum_sha256'])
                && isset($inDbSet[$f['checksum_sha256']])) {
            $f['state'] = 'already_present';
            $reconciled++;
        }
    }
    unset($f);
}
```

Also add `'db_reconciled' => $reconciled` to the existing `gighive_manifest_append_upload_trace`
call immediately after, so the trace shows how many files were promoted.

This ensures that `pending` on disk is always reconciled against DB reality at resume time.
Files already in the DB are promoted to `already_present` before the browser ever sees them,
eliminating the inflated count entirely.

### Scope

- One bulk `WHERE checksum_sha256 IN (...)` query — no N+1 risk.
- Only affects the resume path; normal first-run uploads are unaffected.
- Does not modify `upload_status.json` on disk (read-time reconciliation only).

---

## Testing

### Natural Test (most realistic)

1. Start an upload of a batch you've uploaded before (or partially uploaded). Some files will already be in the DB.
2. Before restarting, check the raw disk state:
   ```bash
   docker exec apacheWebServer cat /var/www/private/tus-hooks/<job_id>/upload_status.json \
     | python3 -m json.tool | grep -c '"state": "pending"'
   ```
3. Click **Restart Upload** and observe the pending count in the UI.
4. **Before fix**: pending count matches the inflated disk count.
5. **After fix**: pending count is lower — files already in DB appear as `already_present`, not `pending`. The trace log should also show `resume_pending_note`.

### Controlled Test (repeatable, no waiting for a real upload)

1. Pick an existing completed job and find its `upload_status.json`:
   ```bash
   docker exec apacheWebServer find /var/www/private/tus-hooks -name upload_status.json | head -1
   ```
2. Note several `checksum_sha256` values from files that **are** in `assets`:
   ```bash
   docker exec mysqlServer mysql -u root -p"" music_db \
     -e "SELECT checksum_sha256 FROM assets LIMIT 5;" 2>/dev/null | tail -5
   ```
3. Manually set those entries to `pending` in the `upload_status.json` on disk.
4. Trigger `import_manifest_upload_start.php` for that job (or click Restart Upload in the UI).
5. **Verify**: those files come back as `already_present` in the returned file list, not `pending`. The inflated count is gone.

### Assertions

| Check | Where to verify |
|-------|----------------|
| `already_present` count matches DB hits | Trace log `resume_pending_note` message shows correct numbers |
| No full re-uploads triggered | TUS log shows 0-byte resumes or skips for those files |
| First-run upload unaffected | Run a fresh job — verify `already_present` count = 0 at start |

The `resume_pending_note` trace message (Issue 1 fix) doubles as a built-in observable — if it reports e.g. `"3 file(s) marked pending"` but the UI shows 0 pending, the DB reconciliation is working.

---

## Related Docs

- `docs/process_upload_statuses_definition.md` — full upload state definitions
- `docs/guide_ai_worker_tagging.md` — what happens after upload completes
