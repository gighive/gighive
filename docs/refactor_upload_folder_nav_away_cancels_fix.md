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
2. The `uploadWorker` async loop (`Promise.all` of concurrent workers, lines ~765–784) is killed.
3. The `uploadPollTimer` `setInterval` is cleared.
4. The entire `_S[id]` state object — `uploadFiles`, `uploadTrace`, `_fileList`, `jobId` — is **destroyed**.

On return, `_S` re-initializes to its defaults (`runState: 'idle'`, `jobId: null`, upload panel hidden). The page looks like a fresh start with no indication of what was in progress.

## What Is Recoverable vs. Lost

| Item | Status |
|---|---|
| TUS partial upload data on server | **Recoverable** — tusd retains incomplete upload chunks; TUS protocol supports resumption |
| Already-completed files (`db_done` / `thumbnail_done`) | **Safe** — committed to DB; checksum dedup prevents re-upload |
| Upload trace / log | **Lost** — in-memory only |
| Upload file list (`_fileList`) | **Lost** — in-memory only; user must re-select the source folder |
| `jobId` in `_S` | **Lost** — user must find it via Previous Jobs (Recovery) |

## How to Resume After Navigating Away

1. Return to the import page.
2. Re-select the source folder via the folder picker (needed because `_fileList` is gone).
3. Expand **Previous Jobs (Recovery)**.
4. Select the interrupted job from the dropdown.
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
Write `jobId` and the upload file state to `sessionStorage` whenever they change. On page load, detect a stale in-progress job and automatically show the Recovery panel pre-populated with that job, prompting the user to resume.

### Option C — server-side upload orchestration (high effort)
Move upload orchestration server-side so the browser is only reporting progress, not driving the transfers. Requires a significant architectural change.

## Recommendation
**Option A** is a safe, minimal guard with no architectural change. **Option B** adds meaningful UX recovery with modest effort and is the natural complement to the existing Recovery panel. Both can be implemented independently.
