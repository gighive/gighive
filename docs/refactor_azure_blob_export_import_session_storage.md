# Refactor: Azure Blob Export/Import — sessionStorage Job Recovery

## Status — 2026-07-26

Implemented. All JavaScript changes landed in `admin_system.php` on 2026-07-26. Pending manual verification on devvm.

---

## Rationale

Section E (Export Media Archive) and Section F (Import Media Archive) on
`admin_system.php` spawn long-running background workers that can take several
minutes to complete. If the admin accidentally refreshes the page mid-run
(e.g. Ctrl+R), all JavaScript state is lost. The worker continues running
server-side, but the UI resets to its idle state with no indication that a job
is in progress. The admin has no way to reconnect to the running job short of
waiting for the operation to complete and then checking file counts on disk.

This was observed in practice during the initial Azure Blob Import testing
(2026-07-26): an accidental Ctrl+R mid-import caused the Section F UI to reset
while the worker was still downloading 34 GB of media files.

The comparable page, `admin_database_load_import_media_from_folder.php`, handles
this via a server-side job list (`import_manifest_jobs.php`) that is queried on
every page load to re-populate a "Previous Jobs" dropdown. Sections E and F do
not have an equivalent job enumeration endpoint; they only have per-job status
endpoints (`export_media_status.php`, `import_media_zip_status.php`).

---

## UX Considerations

### What the admin sees after Ctrl+R (job still running)

The page reloads and the `DOMContentLoaded` re-attach logic fires immediately.
The status area scrolls into view and shows a reconnect banner, the button is
disabled, and live progress resumes polling every 1.5 seconds — identical to
the normal (non-refresh) flow:

```
⬤ Reconnected to in-progress job 4c5c471c2638481b. Restoring progress…

[In progress…]   ← button, disabled

● List blobs      OK    48 blobs found
● Import files    ▓▓▓▓▓▓▓░░░░░  31 / 48   (64%)   ← updates live
● Import files    ▓▓▓▓▓▓▓▓▓▓░░  41 / 48   (85%)
● Import files    OK    47 added (34.2 GB), 1 already present
```

The **only visible difference** from a normal run is the "Reconnected…" banner
at the top instead of the prepare/confirm steps that preceded the job.

### What the admin sees when the job finishes (terminal state)

There are three options for what to display once the polling terminal callback
fires. This refactor chooses **Option 3** (simplified banner):

| Option | What is shown at completion | Effort |
|--------|----------------------------|--------|
| **1** — Extract render into named functions | Full step table identical to normal flow | Highest — requires refactoring `doExportMedia()` / `doImportFromAzure()` |
| **2** — Pass render logic as `onTerminal` callback | Full step table identical to normal flow | Medium — adds a parameter to `resumeJobPolling` and each call site |
| **3** — Generic banner (chosen) | `✓ Job <id> completed successfully.` or `✗ Job <id> finished with errors.` | Lowest — fully self-contained in `resumeJobPolling` |

**Why Option 3 is sufficient:** the admin watched live step-by-step progress
throughout the reconnect period. By the time the job finishes they already know
what happened (files added, errors, etc.). A simple done/error confirmation is
all that is needed. Options 1 and 2 can be revisited if full fidelity is ever
required.

---

## Goal

**Sections E and F must survive an accidental Ctrl+R page refresh: if a job is
in-flight when the page reloads, the UI reconnects automatically and resumes
displaying live progress.**

---

## Industry Precedent

- Browser-based file uploaders (e.g. Uppy, tus-js-client) use `sessionStorage`
  to persist upload IDs across page refreshes so uploads resume transparently.
- Jenkins and GitHub Actions web UIs attach to a running build on page reload by
  embedding the build ID in the URL or in browser session state.
- `sessionStorage` is the standard mechanism for within-tab ephemeral state that
  must survive a page refresh but should not leak to other tabs or persist after
  the tab is closed.

---

## Decision

Use `sessionStorage` with two keys:

| Key | Purpose |
|-----|---------|
| `gh_export_job` | Active export job — JSON `{jobId, dest}` |
| `gh_import_job` | Active import job — JSON `{jobId, source, prefix?}` |

`sessionStorage` is preferred over `localStorage` because:
- It survives `Ctrl+R` within the same tab (the target use case).
- It is automatically cleared when the tab is closed — no stale job IDs from
  previous sessions.
- No new server endpoints are needed; the existing per-job status endpoints are
  sufficient.

Local tar.gz export (Section E, "Download to browser") is excluded from recovery
because the browser download stream cannot be resumed after a refresh. Only the
Azure Blob export path stores a `gh_export_job` key.

---

## Current State

### Section E — Export

`doExportMedia()` in `admin_system.php`:
1. POSTs to `export_media.php` to start a worker, receives `job_id`.
2. Polls `export_media_status.php?job_id=...` until done or error.
3. All state (job ID, step list, button labels) is in JS memory only.

On Ctrl+R: state lost, UI resets, worker continues unobserved.

### Section F — Import

`doImportMediaZip()` (local archive) and `doImportFromAzure()` (Azure):
1. POSTs to `import_media_zip.php` (mode=start), receives `job_id`.
2. Polls `import_media_zip_status.php?job_id=...` until done or error.
3. All state in JS memory only.

On Ctrl+R: state lost, UI resets, worker continues unobserved.

---

## Proposed Implementation

### Files Under Change

1. **Modified** — `ansible/roles/docker/files/apache/webroot/admin/admin_system.php`
   — JavaScript only, no PHP changes.

No server-side files change. No new endpoints. No Ansible smoke tests needed
(no new PHP endpoints introduced).

---

### admin_system.php — JavaScript Changes

#### 1. Save job to sessionStorage on start

In `doExportMedia()`, immediately after receiving `job_id` from the Azure
export start response (the `dest === 'azure'` branch only):

```js
_activeJob = true;
sessionStorage.setItem('gh_export_job', JSON.stringify({ jobId: jobId, dest: 'azure' }));
```

In `doImportMediaZip()` (local branch — the function already branches to `doImportFromAzure()` for Azure, so this save is for the local path only), immediately after receiving `job_id`:

```js
_activeJob = true;
sessionStorage.setItem('gh_import_job', JSON.stringify({ jobId: jobId, source: 'local' }));
```

In `doImportFromAzure()`, immediately after receiving `job_id`:

```js
_activeJob = true;
const prefixValue = (document.getElementById('import_blob_prefix').value || '').trim();
sessionStorage.setItem('gh_import_job', JSON.stringify({ jobId: jobId, source: 'azure', prefix: prefixValue }));
```

`_activeJob = true` must be set here (not only in `resumeJobPolling`) so that
the `beforeunload` guard fires on the very first Ctrl+R, before any page reload
has occurred.

#### 2. Clear sessionStorage and reset flag on terminal state

In each polling callback, in the `done` and `error`/`state !== 'running'`
branches:

```js
_activeJob = false;
sessionStorage.removeItem('gh_export_job');  // export polling callback
// or
_activeJob = false;
sessionStorage.removeItem('gh_import_job');  // import polling callbacks
```

`_activeJob` must be reset here alongside `removeItem`. `resumeJobPolling` (§5)
already does both in its terminal callback; the normal (non-reconnect) polling
callbacks in `doExportMedia()` and `doImportMediaZip()`/`doImportFromAzure()`
must do the same.

#### 3. Re-attach on page load (DOMContentLoaded)

Add a **second** `addEventListener('DOMContentLoaded', ...)` call (the existing
one at line ~1004 is for an unrelated toggle and must not be modified) that
checks both keys and re-attaches polling if the job is still running:

```js
document.addEventListener('DOMContentLoaded', function () {
  // --- Section F import recovery ---
  var savedImport = sessionStorage.getItem('gh_import_job');
  if (savedImport) {
    try {
      var imp = JSON.parse(savedImport);
      if (imp && imp.jobId) {
        resumeJobPolling(
          'gh_import_job', 'import_media_zip_status.php', imp.jobId,
          document.getElementById('importZipStatus'),
          document.getElementById('importZipBtn'), 'Import Archive'
        );
      }
    } catch (e) { sessionStorage.removeItem('gh_import_job'); }
  }

  // --- Section E Azure export recovery ---
  var savedExport = sessionStorage.getItem('gh_export_job');
  if (savedExport) {
    try {
      var exp = JSON.parse(savedExport);
      if (exp && exp.jobId) {
        // dest is always 'azure' here — local export is never stored.
        resumeJobPolling(
          'gh_export_job', 'export_media_status.php', exp.jobId,
          document.getElementById('exportMediaStatus'),
          document.getElementById('exportMediaBtn'), 'Send to Azure'
        );
      }
    } catch (e) { sessionStorage.removeItem('gh_export_job'); }
  }
});
```

`admin_system.php` already has a `DOMContentLoaded` listener at line ~1004 for
an unrelated toggle. Add a **second** `addEventListener('DOMContentLoaded', ...)`
call — multiple listeners are supported and both fire. Place it at the bottom of
the Section E/F `<script>` block, near `doExportMedia()` and `doImportMediaZip()`.

#### 4. In-memory active-job flag

Instead of re-checking sessionStorage in `beforeunload` (which would trigger
spuriously if a key failed to clear after job completion), maintain a module-level
flag:

```js
let _activeJob = false;  // set true on start, false in every terminal callback
```

The `beforeunload` guard checks `_activeJob`, not sessionStorage:

```js
window.addEventListener('beforeunload', function (e) {
  if (_activeJob) { e.preventDefault(); e.returnValue = ''; }
});
```

The re-attach logic (step 3) also sets `_activeJob = true` when it finds a
running job, and the terminal callbacks set it back to `false`.

#### 5. Unified resume helper

Rather than two separate `resumeImportPolling` and `resumeExportPolling`
functions that duplicate the same reconnect skeleton, use one shared helper:

```js
function resumeJobPolling(storageKey, statusEndpoint, jobId, statusEl, btn, originalBtnLabel) {
  _activeJob = true;
  if (btn) { btn.disabled = true; btn.textContent = 'In progress…'; }
  if (statusEl) {
    statusEl.innerHTML = '<div class="alert-ok" style="border-color:#3b82f6">' +
      'Reconnected to in-progress job ' + escapeHtml(jobId) + '. Restoring progress…</div>';
    statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  var _cleaned = false;
  function _cleanup() {
    if (_cleaned) return;
    _cleaned = true;
    clearTimeout(stalenessTimer);
    _activeJob = false;
    sessionStorage.removeItem(storageKey);
    if (btn) { btn.disabled = false; btn.textContent = originalBtnLabel; }
  }

  // _resetStaleness() is called at startup and on every successful onProgress tick.
  // The timer therefore means "60 s of server silence" not "60 s of total job time".
  // A multi-hour import is safe as long as the status endpoint keeps responding.
  var stalenessTimer = null;
  function _resetStaleness() {
    clearTimeout(stalenessTimer);
    stalenessTimer = setTimeout(function () {
      if (_cleaned) return;
      poll.stop();
      _cleanup();
      if (statusEl) {
        statusEl.innerHTML = '<div class="muted">⚠ Could not reconnect to previous job '
          + escapeHtml(jobId) + ' (it may have completed or been cleared).<br>'
          + 'Check file counts on disk to confirm the operation succeeded.</div>';
      }
    }, 60000);
  }

  var poll = pollJobStatus(jobId, statusEndpoint, null, function (state, data) {
    _cleanup();
    // Render final state (done or error) — Option 3: generic banner.
    // _cleanup() is idempotent; if stalenessTimer already ran this is a no-op.
  }, 1500, null, function (data) {
    _resetStaleness();  // server is alive — push the watchdog forward
    // update steps display using existing render helper
  });

  _resetStaleness();  // start initial 60 s watchdog window
}
```

Confirmed element IDs from `admin_system.php` HTML:

| Section | Status div | Button | Original label |
|---------|-----------|--------|----------------|
| E Export (Azure) | `exportMediaStatus` | `exportMediaBtn` | `'Send to Azure'` |
| F Import | `importZipStatus` | `importZipBtn` | `'Import Archive'` |

Note: the export button label is dynamic (`'Send to Azure'` vs `'Download Archive'`)
but sessionStorage is only written for the Azure path, so `originalBtnLabel` for
export reconnect is always `'Send to Azure'`.

#### 6. Stale / invalid job ID handling

`pollJobStatus` retries indefinitely on any HTTP error — its `.catch()` handler
just reschedules the next poll (see `assets/import_progress.js` line 212). A 404
(e.g. temp dir gone after server restart) therefore never calls `onDone`; the
terminal callback in the normal reconnect flow is unreachable for this case.

The fix is the **reset-on-tick staleness watchdog** in `resumeJobPolling` (§5 above).
The key design rule:

> **Staleness = server silence, not elapsed job time.**

The watchdog works as follows:
- A 60-second timer is started when `resumeJobPolling` is first called.
- Every `onProgress` tick (a valid JSON response from the status endpoint) calls
  `_resetStaleness()`, which cancels the current timer and starts a fresh 60-second
  window. The poll interval is 1500 ms, so with a healthy server the timer is reset
  every ~1.5 s and never fires.
- If the server goes silent — due to a restart, the temp dir disappearing, or
  sustained network failure — `onProgress` stops being called. After 60 consecutive
  seconds with no valid response (≈ 40 failed poll attempts), the watchdog fires,
  stops polling, and shows the neutral banner:

```
⚠ Could not reconnect to previous job (it may have completed or been cleared).
  Check file counts on disk to confirm the operation succeeded.
```

This design is safe for multi-hour imports: a large Azure blob import that takes
hours will keep resetting the watchdog on every 1.5 s tick and will never trigger
the neutral banner as long as the server is responding.

#### 7. Scroll-into-view on reconnect

Sections E and F are below the fold. The `statusEl.scrollIntoView(...)` call in
`resumeJobPolling` (step 5 above) ensures the admin sees the reconnected progress
banner without having to scroll manually.

---

### SonarQube / Best-Practice Notes

- **Pure JS change** — no PHP, no SQL, no Swift. No RSPEC violations anticipated.
- `_activeJob` flag is module-level but not globally exported; keep it in the
  same `<script>` block as the existing Section E/F JS to avoid leaking scope.
- `sessionStorage` access in `DOMContentLoaded` is synchronous and safe.
- `scrollIntoView` is supported in all target browsers (Chrome, Safari, Edge on
  the admin-only internal network).

---

## Wireframe — Section F after Ctrl+R (reconnected state)

```
Section F: Import Media Archive
────────────────────────────────────────────────────────────────
 ⚠ Reconnected to in-progress Azure import (job 4c5c471c2638481b).
   Progress is being restored…

 [In progress…]   ← button, disabled (label set by resumeJobPolling)

 ● List blobs      OK     48 blobs found
 ● Import files    ▓▓▓▓▓▓▓▓▓░░░░  31 / 48   (64%)
```

The button label reverts to `'Import Archive'` (the single import button's
original label) and becomes re-enabled when the job reaches a terminal state.

---

## Testing Checklist

- [ ] Start an Azure import, immediately Ctrl+R — UI reconnects, progress resumes.
- [ ] Start an Azure import, let it complete normally — no sessionStorage key
      remains after completion.
- [ ] Start an Azure import, Ctrl+R after completion — no reconnect banner shown
      (key already cleared).
- [ ] Start a local archive import, Ctrl+R mid-run — UI reconnects.
- [ ] Start an Azure export (Send to Blob), Ctrl+R mid-run — UI reconnects.
- [ ] Start a local tar.gz export, Ctrl+R — no reconnect (expected; download
      stream is gone). UI resets to idle, which is correct.
- [ ] Status endpoint returns HTTP 404 or server error mid-reconnect — neutral banner shown, button restored to idle, no error alert.
- [ ] Ctrl+R while a job is active shows a "Leave site?" browser prompt.
- [ ] Cancel the leave prompt — page stays, polling continues.
- [ ] Confirm the leave prompt — page reloads, reconnect fires.
- [ ] Job completes after Ctrl+R but before the first poll fires — first poll returns `done`, success banner shown, key cleared, no spurious "running" state.
- [ ] Open the same admin URL in a second tab while a job is in-flight in tab 1
      — tab 2 does NOT auto-reconnect (sessionStorage is per-tab by design).
- [ ] Close and reopen the tab after a job completes — no ghost reconnect
      attempt (sessionStorage cleared on close).

---

## Progress

### Completed

- `sessionStorage` save + `_activeJob = true` on job start (export Azure, import local, import Azure).
- `_activeJob = false` + `sessionStorage.removeItem` in all three `.finally()` callbacks.
- `resumeJobPolling` unified helper with `_cleaned` guard, staleness timeout, and live progress ticks.
- `escapeHtml` helper for safe job ID rendering.
- `beforeunload` guard checking `_activeJob`.
- Second `DOMContentLoaded` listener for import + export re-attach on reload.

### Remaining — This Feature

- Manual verification against Testing Checklist on devvm.

### Remaining — Follow-on Tasks

- Consider adding a "Dismiss" button to the reconnected banner so the admin can
  abandon monitoring a job they do not care about without triggering the leave
  prompt.
- Consider similar recovery for `admin_database_catalog_media_from_folder.php`
  if that page gains long-running background workers in the future.
