# Navigating Away from Import Page Cancels Active Upload

## File
`ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php`

## Observed Behavior
If the user hits Back (or navigates away) while Step 2 file uploads are in progress, then returns to the page, the upload is no longer running and must be manually resumed.

## Root Cause Analysis

### Step 1 (DB import / manifest job) — not affected

The server-side PHP import job runs in a background process independently of the browser. `pollManifestJob()` is a `setTimeout`-based loop that only monitors the job — it does not drive it. Hitting Back kills the poller, but the server job keeps running to completion. On return, the job's final state is visible under **Previous Jobs (Recovery)**.

### Step 2 (TUS file uploads) — affected

The uploads are **entirely browser-driven**. The `tus-js-client` library streams bytes from the user's local files to the server via HTTP. All upload progress, state, and orchestration live in the in-memory `_S[id]` object. When the user navigates away:

1. All open TUS HTTP connections are **aborted by the browser** mid-stream.
2. The `uploadWorker` async loop (`Promise.all` of concurrent workers, lines ~795–814) is killed.
3. The `uploadPollTimer` `setInterval` is cleared.
4. The entire `_S[id]` state object — `uploadFiles`, `uploadTrace`, `_fileList`, `jobId` — is **destroyed**.

On return, `_S` re-initializes to its defaults (`runState: 'idle'`, `jobId: null`, upload panel hidden). The page looks like a fresh start with no indication of what was in progress.

## What Is Recoverable vs. Lost

| Item | Status |
|---|---|
| TUS partial upload data on server | **Recoverable** — tusd retains incomplete upload chunks; TUS protocol supports resumption |
| Already-completed files (`db_done` / `thumbnail_done`) | **Safe** — committed to DB; checksum dedup prevents re-upload |
| Upload trace / log | **Partially lost** — client-only trace events in `s.uploadTrace` are discarded; server-side trace in `upload_trace.jsonl` is preserved and re-fetched via `refreshUploadDebug` once `jobId` is restored |
| Upload file list (`_fileList`) | **Lost** — in-memory only; user must re-select the source folder |
| `jobId` in `_S` | **Lost** — user must find it via Previous Jobs (Recovery) |

## How to Resume After Navigating Away

1. Return to the import page.
2. Re-select the source folder via the folder picker (needed because `_fileList` is gone).
3. Expand **Previous Jobs (Recovery)**.
4. Select the interrupted job from the dropdown (the most recent ok job is auto-selected by `refreshJobsUi` on page load; verify it is the right one).
5. Click **Resume Upload**.

The TUS client will re-contact the server for each partially uploaded file and resume from where the upload left off. Already-completed files are skipped via checksum dedup.

## Proposed Fix Options

### Option A — `beforeunload` warning (low effort)
Add a `window.addEventListener('beforeunload', ...)` handler that fires a browser confirmation dialog when uploads are actively in progress. This doesn't prevent the loss, but warns the user before it happens.

```javascript
window.addEventListener('beforeunload', (e) => {
    const active = ['a', 'b'].some(id => {
        const s = _S[id];
        return s && s.uploadFiles && s.uploadFiles.some(f => f.state === 'uploading' || f.state === 'pending');
    });
    if (active) {
        e.preventDefault();
        e.returnValue = '';
    }
});
```

### Option B — persist `jobId` to `sessionStorage` (medium effort)
Write `jobId` to `sessionStorage` whenever it is assigned. On page load, detect a stale in-progress job and automatically show the Recovery panel pre-populated with that job, prompting the user to resume.

`uploadFiles` per-file state does **not** need to be persisted — `sectionStartUpload` always calls `import_manifest_upload_start.php` which returns fresh per-file states from the server (from `upload_job_files` DB rows after the `refactored_upload_jobs_from_json_to_db` migration, previously from `upload_status.json`). Storing it in `sessionStorage` would be redundant overhead.

**Cleanup on success required:** The `sessionStorage` key must be cleared when `_batchState` is set to `complete_success`, otherwise every subsequent page load — including after a fully successful upload — will detect the stale key and incorrectly trigger the Recovery panel.

**Important limitation:** `File` objects from the folder picker (`_fileList`) are browser-managed handles and are **not serializable** — they cannot be stored in `sessionStorage`. Even with Option B fully implemented, the user must still re-select the source folder before the upload can proceed, because `tus-js-client` needs the actual file handles to read bytes. What Option B buys is automatic `jobId` restoration and pre-populated recovery UI; it does not eliminate the folder re-selection step.

### Option C — server-side upload orchestration (high effort)
Move upload orchestration server-side so the browser is only reporting progress, not driving the transfers. Requires a significant architectural change.

## Recommendation
**Option A** is a safe, minimal guard with no architectural change. **Option B** adds meaningful UX recovery with modest effort and is the natural complement to the existing Recovery panel. Both can be implemented independently.

---

## Logic Review (2026-06-11)

Full plan reviewed against `admin_database_load_import_media_from_folder.php` source. Findings addressed:

- **Line reference corrected** — `uploadWorker`/`Promise.all` is at lines ~795–814, not ~765–784.
- **Upload trace recoverability clarified** — client-only `s.uploadTrace` events are discarded; server-side `upload_trace.jsonl` is preserved and re-fetched via `refreshUploadDebug` once `jobId` is restored. Row updated to "Partially lost".
- **Recovery step 4 clarified** — `refreshJobsUi` auto-selects the most recent ok job on page load; user should verify the right job is selected.
- **Option B `uploadFiles` persistence removed** — `sectionStartUpload` always fetches fresh per-file state from `import_manifest_upload_start.php`; storing `uploadFiles` in `sessionStorage` is redundant. Only `jobId` needs persisting.
- **Option B cleanup gap fixed** — added requirement to clear `sessionStorage` key on `complete_success` to prevent stale recovery prompt after a successful upload.
- **TUS resumption logic verified** — tus-js-client stores upload offsets in localStorage keyed by fingerprint; same file + same metadata on re-select produces the same fingerprint and resumes correctly.
- **Resume step order verified** — `sectionResumeUpload` reads `_fileList` from the folder picker at click time; folder re-selection must precede clicking Resume Upload.
- **Option A `beforeunload` logic verified** — null-guards and state filter (`uploading` | `pending`) are correct.
