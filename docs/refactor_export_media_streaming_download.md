# Refactor: Export Media — Streaming Browser Download

## Status — 2026-09-04

Complete — all 19 steps implemented.

---

## Elevator Pitch

When you export media files from the admin panel, the current download works by loading
the entire archive into the browser's memory before saving it to your computer. For large
libraries this crashes the browser tab before the file ever reaches your disk. This
refactor makes the download pipe data directly from the server to a file you choose on
your disk — no matter how large the archive — exactly the way modern cloud storage
services handle large downloads. Chrome and Edge 86+ are required; on other browsers the
download is blocked with a clear message directing admins to rsync or direct volume backup.

---

## Rationale

The `doExportMedia()` download step in `admin_system.php` accumulates every 256 KB network
chunk into a JavaScript array, assembles a `Blob`, and then triggers a save dialog. The full
archive must fit in the browser process's RAM before anything is written to disk. Browser
tabs are typically capped at approximately 4 GB of JavaScript heap on 64-bit systems.

Audio and video files are already in compressed formats (MP3, MP4, AAC). `tar.gz` provides
negligible additional compression — the archive is roughly the same size as the source media
files on disk. A GigHive library at 130 GB produces a ~130 GB archive. That will never fit
in browser RAM and the tab will crash before the download finishes.

The File System Access API (`showSaveFilePicker` + `WritableStream.pipeTo`) streams data
directly from the network response to the OS file handle with no intermediate RAM
accumulation. The memory ceiling is removed entirely on supporting browsers.

This same file (`admin_system.php`) already uses `showSaveFilePicker` + `pipeTo` for the
Section C backup save path. The pattern is deployed and working in production. This
refactor applies it to the Section E export download path.

---

## Goal

Replace the chunk-accumulate-Blob download path in `doExportMedia()` with a streaming
write using the File System Access API. Preserve the per-byte progress bar. Block the
download immediately on browsers that do not support `showSaveFilePicker` with a clear
error directing the admin to Chrome/Edge 86+ or rsync. Update the Section E UI to state
the browser requirement.

**Policy: After this refactor, a Section E local export requires Chrome or Edge 86+.
It is recommended that the full archive not be held in browser RAM.**

---

## Industry Precedent

- Google Drive, Dropbox, and OneDrive stream large file downloads directly to disk.
  Users receive and save data as it arrives rather than waiting for full buffering.
- The WHATWG File System Access API (`showSaveFilePicker` + `WritableStream`) was designed
  explicitly for this use case. Chrome's own developer guidance recommends
  `response.body.pipeThrough(transform).pipeTo(writableStream)` for download flows that
  need progress tracking without RAM accumulation.
- The Section C backup save path in this same file already uses this pattern and it is
  deployed and running in production.

---

## Decision

**Single path — Chrome and Edge 86+ required:** After the user confirms the size dialog,
immediately call `showSaveFilePicker` (while still within the user gesture context) to
obtain a `FileSystemFileHandle`. Build the archive on the server. When the build completes,
open the writable stream (`fileHandle.createWritable()`) and pipe the download response
through a `TransformStream` (for progress counting) into the file writable. No chunks are
held in memory.

**Unsupported browsers — hard block:** If `showSaveFilePicker` is not present in the
browser (Firefox, Safari, older browsers), `doExportMedia()` stops immediately with a
clear error before the prepare fetch is called. No server resources are consumed. The
error message directs the admin to use Chrome or Edge 86+, or rsync / direct volume
backup as alternatives. There is no Blob fallback.

**UI notice:** The Section E description is updated to state the Chrome/Edge 86+
requirement and name the alternatives. When the Azure destination radio is rendered
(Azure credentials configured), the "Download to browser" radio label is also annotated.

No server-side changes are required. `export_media_download.php` already streams the
archive in 256 KB chunks with a correct `Content-Length` header. The fix is contained
entirely to the JS download block in `admin_system.php` and the Section E description HTML.

---

## Benefits / Potential Drawbacks

**Benefits:**

| Benefit | Detail |
|---|---|
| No browser RAM ceiling | Data flows network to OS file handle; archive of any size works on Chrome/Edge 86+ |
| Progress bar preserved | `TransformStream` intercepts bytes in flight; X / Y progress display is unchanged |
| No server changes | `export_media_download.php` is already correct; this is a JS-only change |
| Single output file | Same `gighive_export_*.tar.gz`; no multi-part complexity; Section F import unchanged |
| Pattern proven in same file | Section C backup save already uses `showSaveFilePicker` + `pipeTo` |
| User controls save location | File picker allows save to external drives, NAS mounts, or any chosen path |

**Potential Drawbacks:**

| Drawback | Detail |
|---|---|
| Browser requirement | `showSaveFilePicker` is Chrome and Edge 86+ only. Firefox and Safari admins cannot use this download tool and must use rsync or direct volume backup. |
| File picker before build starts | Picker dialog appears right after the size confirm, before the archive starts building. The user picks a destination before the server has finished. This is the only placement that keeps the call within the browser's user gesture window (see UX Considerations). |
| Picker dismissal cancels the job | Dismissing the file picker after the confirm dialog prevents the build from starting. No server resources are wasted — the worker is not spawned until after the picker returns. |
| Partial file on interrupted download | Streaming writes a partial file if the connection drops. `writable.abort()` is called on error to discard the partial write. A full restart is required to recover. |
| Requires secure context | `showSaveFilePicker` requires HTTPS or localhost. Production GigHive is HTTPS. On local HTTP development environments the browser guard fires and the admin sees the unsupported-browser error. |

---

## Real World Use Cases

| Scenario | Before | After |
|---|---|---|
| Admin on Chrome exports 2 GB tutorial library | Works; browser temporarily holds ~2 GB in RAM | Works; data streams directly to disk — RAM near zero |
| Admin on Chrome exports 130 GB full library | Browser tab crashes with out-of-memory error | Works; picker appears after confirm dialog, data streams to chosen disk location |
| Admin on Firefox attempts export | Works up to ~20 GB (Blob, RAM limited) | Blocked immediately: "requires Chrome or Edge 86+" — no server resources consumed |
| Admin on Safari attempts export | Works up to ~20 GB (Blob, RAM limited) | Blocked immediately: "requires Chrome or Edge 86+" — no server resources consumed |
| Admin on Chrome, Azure configured, selects "Download to browser" | Blob path for local download | Streaming path; radio label annotated with Chrome/Edge requirement |

---

## Design Principles

1. Chrome and Edge 86+ are required for local download. Unsupported browsers receive a hard block at the top of `doExportMedia()` before any server resources are consumed.
2. Progress bar is preserved on the streaming path.
3. No server-side changes. `export_media.php`, `export_media_worker.php`, `export_media_download.php`, and all other PHP files are unchanged.
4. No new hardcoded paths or literals that belong in `group_vars`. Relative endpoint URLs (`'export_media_download.php'`) are consistent with the existing code pattern.
5. `showSaveFilePicker` must be called within the browser's user gesture window — specifically right after `window.confirm()` returns true, before any `await fetch()` call to the start endpoint.
6. If the picker is dismissed (`AbortError`), the worker must not be spawned. No server resources are consumed.
7. On streaming error or worker failure, `writable.abort()` is called to discard any partial file write.

---

## Current State

The download block (Step 4) in `doExportMedia()` currently:

1. `fetch('export_media_download.php?job_id=...')` — opens the HTTP response.
2. `response.body.getReader()` — streams the response body chunk by chunk.
3. `chunks.push(value)` inside a `while` loop — every chunk is held in a JS array in RAM.
4. After the loop finishes (all data in RAM): `new Blob(chunks)` — assembles the full archive in browser memory.
5. `URL.createObjectURL(blob)` + hidden `<a>` click — triggers save to the default Downloads folder.
6. `URL.revokeObjectURL(url)` — releases the blob URL.

The Section E description text makes no mention of browser compatibility requirements for
the download path. Users on Chrome have no indication that Firefox users face a
memory-based size limit, and vice versa.

---

## Proposed Implementation

### Implementation Checklist

- [x] **Step 1** — Add browser compatibility notice sentence to Section E `<p class="muted">` in `admin_system.php`
- [x] **Step 2** — Annotate the "Download to browser (tar.gz)" radio label text inside the `$__azure_available` block
- [x] **Step 3** — Add unsupported-browser hard block (Change A0); replace the Step 4 download block with the streaming-only path — no Blob fallback (includes `createWritable()` error handling and `_activeJob` flag)
- [x] **Step 4** — Add `addInitScript` mock to the Playwright regression test — **must commit in the same change as Step 3** (without it Playwright hangs on the OS file picker)
- [x] **Step 5** — Fix `window.confirm()` local-path text: replace "zip" with "archive" and remove the stale "free space" sentence
- [x] **Step 6** — Add standalone Playwright test verifying the compatibility notice text is present in Section E
- [x] **Step 7** — Update `process_admin_export_media.md` download-path description to reflect the streaming behavior
- [x] **Step 8** — Add Playwright test verifying the unsupported-browser hard block: simulate absent `showSaveFilePicker`, click the button, confirm error message appears with no server call
- [x] **Step 9** — Add `disk_free_space` guard to `export_media.php` prepare mode (local path only): HTTP 507 with clear MB figures if server `/tmp` has insufficient space
- [x] **Step 10** — Update `process_admin_export_media.md` with two-hop transfer-flow one-liner and client-side space caveat (after Step 7 streaming description)
- [x] **Step 11** — Add Playwright `page.route()` mock test: simulate a 507 prepare response, verify error appears in `#exportMediaStatus` and button re-enables
- [x] **Step 12** — Add `alert-ok` success banner to local download path in `admin_system.php` (matches Azure path pattern exactly — `+=`, `style="margin-top:.75rem"`, `fmtElapsed`)
- [x] **Step 13** — Strengthen regression test Step 2 assertion from `not.toBeEmpty` to `toContainText('Export complete')` on `#exportMediaStatus .alert-ok` with 300 s timeout

---

### Step 1 — Section E description text

**File:** `admin_system.php`
**Location:** Section E `<p class="muted">` block (lines 503–510, the paragraph beginning
"Download a tar.gz archive of media files currently on disk…")

Two sentences are appended to that paragraph, immediately before the closing `</p>` (now line 511 after the first addition):

The streaming-restart notice (already added to the live file):

> This is a streaming copy — if interrupted, you will have to start the stream again from scratch.

The browser requirement notice (this step):

> Requires Chrome or Edge 86+ — on other browsers this download is not available; use rsync or direct volume backup instead.

This is a text-only change. No PHP logic, no element IDs, no event handlers, and no
JS references change.

### Step 2 — Azure destination radio label

**File:** `admin_system.php`
**Location:** Inside the `if ($__azure_available):` block (lines 527–530), the label for
the `export_dest_local` radio.

Change the label text from:

```
Download to browser (tar.gz)
```

to:

```
Download to browser (tar.gz) — streams to disk on Chrome/Edge 86+; buffered in RAM on other browsers
```

This label is only rendered when Azure credentials are configured in the container
environment, so it does not affect non-Azure installs. No element ID, radio value, or
`onchange` handler changes.

**SonarQube / Best-Practice Notes:**
- HTML/text change only. No new PHP logic.
- No new literals that belong in `group_vars` — this is display text, not a path,
  credential, or deployment-specific value.

**Verification:**
Load `/admin/admin_system.php` in a browser. Confirm the compatibility notice appears in
the Section E description paragraph. On an Azure-configured instance, confirm the radio
label shows the annotation. On a non-Azure instance, confirm no label change is visible
(the radio is not rendered).

---

### User gesture and file picker timing

`showSaveFilePicker` requires a browser user activation (a direct user gesture). The
user activation from the "Download Archive" button click is consumed by the first
`await fetch()` in the prepare step. A fresh activation is created when `window.confirm()`
returns — the user clicked OK, which is itself a user gesture in Chrome's activation model.

The picker call must therefore occur immediately after `window.confirm()` returns `true`
and before any subsequent `await` call. The existing `exportRun()` flow is:

```
prepare → window.confirm() → [INSERT PICKER HERE] → start → poll → download
```

This placement also improves resource efficiency: if the user dismisses the picker
(`AbortError`), the worker is never spawned, and no server temp directory is created.

### Step 3 — Replace the Step 4 download block

**File:** `admin_system.php`
**Location:** `doExportMedia()` — `exportRun()` — two insertion points within this function.

**Change A0 — Unsupported-browser guard at the top of `doExportMedia()`:**

Insert immediately after the `dest` variable is read (line 1240), before `btn.disabled`:

```javascript
// Block unsupported browsers immediately — before any server resources are consumed.
// statusEl is already declared above this insertion point (line 1238).
if (dest === 'local' && !('showSaveFilePicker' in window)) {
  statusEl.innerHTML = '<div class="alert-error" style="margin-top:.75rem">'
    + 'This download requires Chrome or Edge 86+. '
    + 'On other browsers, use rsync or direct volume backup instead.</div>';
  return;
}
```

The Azure path is unaffected — the guard only fires when `dest === 'local'`.

**Change A — Picker call immediately after `window.confirm()` (inside `exportRun`):**

The existing code after the `window.confirm()` block is:

```javascript
steps[1] = { name: workerStepName, status: 'running', ... };
render();
// ── Step 2: Start async worker ─────────────────────────────────────────
let startResp, startData;
startResp = await fetch('export_media.php', { ... mode: 'start' ... });
```

Insert the following block between `render()` and the `await fetch` start call.
`showSaveFilePicker` is guaranteed present for `dest === 'local'` — the guard above
ensures it. The `if (dest === 'local')` check here covers only the Azure path:

```javascript
// ── Streaming path: obtain file handle now (inside window.confirm() gesture) ──
// showSaveFilePicker must be called before the first await fetch() that follows.
// window.confirm() returning true creates a fresh user activation in Chrome.
let _streamFileHandle = null;
let _suggestedName    = 'gighive_export.tar.gz'; // fallback; refined below
if (dest === 'local') {
  const _now = new Date();
  const _p   = n => String(n).padStart(2, '0');
  const _ds  = _now.getFullYear()
               + _p(_now.getMonth() + 1) + _p(_now.getDate())
               + '_' + _p(_now.getHours()) + _p(_now.getMinutes()) + _p(_now.getSeconds());
  const _lbl = orgName !== '' ? orgName.replace(/[^a-zA-Z0-9_\-]/g, '_') : 'all';
  const _tp  = fileType !== 'all' ? '_' + fileType : '';
  _suggestedName = 'gighive_export_' + _lbl + _tp + '_' + _ds + '.tar.gz';
  try {
    _streamFileHandle = await window.showSaveFilePicker({
      suggestedName: _suggestedName,
      types: [{ description: 'GigHive archive', accept: { 'application/gzip': ['.tar.gz', '.tgz'] } }]
    });
  } catch (err) {
    if (err.name === 'AbortError') {
      // dest is always 'local' here (outer guard in Change A0 ensures it)
      steps[1] = { name: workerStepName, status: 'pending', message: 'Canceled.', progress: null };
      steps[2] = { name: 'Download', status: 'pending', message: '', progress: null };
      render();
      return; // worker not spawned — no server resources consumed
    }
    // Non-AbortError (e.g. security error): surface to user rather than silently degrading.
    steps[1] = { name: workerStepName, status: 'error', message: 'File picker error: ' + err.message };
    render();
    return;
  }
}
```

**Change B — Replace the Step 4 download block with a streaming / Blob branch:**

The existing Step 4 block (from `steps[2] = { name: 'Download', status: 'running'...`
through `URL.revokeObjectURL(url)`) is replaced entirely. There is no `else` branch —
`_streamFileHandle` is always non-null here because Change A0 blocks the function before
the prepare fetch when `showSaveFilePicker` is absent:

```javascript
// ── Step 4: Download (streaming — Chrome / Edge 86+ only) ─────────────────
  steps[2] = { name: 'Download', status: 'running', message: 'Connecting\u2026', progress: null };
  render();

  let dlResp;
  try {
    dlResp = await fetch('export_media_download.php?job_id=' + encodeURIComponent(jobId));
  } catch (err) {
    steps[2] = { name: 'Download', status: 'error', message: 'Network error: ' + err.message };
    render();
    return;
  }
  if (!dlResp.ok || !(dlResp.headers.get('Content-Type') || '').startsWith('application/gzip')) {
    const errData = await dlResp.json().catch(() => null);
    const msg = (errData && (errData.error || errData.message))
      ? String(errData.error || errData.message) : 'HTTP ' + dlResp.status;
    steps[2] = { name: 'Download', status: 'error', message: msg };
    render();
    return;
  }

  const contentLength   = parseInt(dlResp.headers.get('Content-Length') || '0', 10) || 0;
  const effectiveLength = contentLength || archiveBytes;
  const cd    = dlResp.headers.get('Content-Disposition') || '';
  const match = cd.match(/filename="([^"]+)"/);
  const fname = match ? match[1] : _suggestedName;

  steps[2] = { name: 'Download', status: 'running',
               message: effectiveLength > 0 ? '0 B / ' + fmtBytes(effectiveLength) : 'Receiving\u2026',
               progress: effectiveLength > 0 ? { processed: 0, total: effectiveLength } : null };
  render();

  let received       = 0;
  let lastYieldPct   = -1;
  let lastYieldBytes = 0;

  const progressTransform = new TransformStream({
    transform(chunk, controller) {
      controller.enqueue(chunk);
      received += chunk.byteLength;
      if (effectiveLength > 0) {
        const pct = received / effectiveLength;
        if (pct - lastYieldPct >= 0.01) {
          lastYieldPct = pct;
          steps[2] = { name: 'Download', status: 'running',
                       message: fmtBytes(received) + ' / ' + fmtBytes(effectiveLength),
                       progress: { processed: received, total: effectiveLength } };
          render();
        }
      } else {
        if (received - lastYieldBytes >= 5 * 1048576) {
          lastYieldBytes = received;
          steps[2] = { name: 'Download', status: 'running',
                       message: fmtBytes(received) + ' received\u2026', progress: null };
          render();
        }
      }
    }
  });

  // createWritable() here — not at picker time — so no empty file exists during the build.
  // Wrap separately: a disk-full or permission error here needs its own error path.
  let fileWritable;
  try {
    fileWritable = await _streamFileHandle.createWritable();
  } catch (err) {
    steps[2] = { name: 'Download', status: 'error', message: 'Could not open save location: ' + err.message };
    render();
    return;
  }

  // Set _activeJob so the beforeunload handler warns if the user tries to navigate
  // away mid-stream (a partial file would otherwise be left silently on disk).
  _activeJob = true;
  try {
    await dlResp.body.pipeThrough(progressTransform).pipeTo(fileWritable);
    // pipeTo() closes fileWritable on success automatically
  } catch (err) {
    await fileWritable.abort().catch(() => {});
    steps[2] = { name: 'Download', status: 'error', message: 'Stream error: ' + err.message };
    render();
    return;
  } finally {
    _activeJob = false;
  }

  steps[2] = { name: 'Download', status: 'ok',
               message: fname + ' (' + fmtBytes(received) + ')',
               progress: { processed: received || effectiveLength, total: effectiveLength || received } };
  render();

```

**Implementation notes:**

- Change A0 ensures `showSaveFilePicker` is present before `exportRun()` is ever called
  for `dest === 'local'`. `_streamFileHandle` is therefore always non-null at Step 4.
  There is no Blob fallback branch.
- `fileHandle.createWritable()` is called immediately before `pipeTo` — not at picker
  time. The OS file is created at this point. If the build failed before reaching this
  line, no file is created at the chosen location. A separate try/catch around
  `createWritable()` surfaces disk-full and permission errors as a visible error state.
- `_activeJob` is set to `true` immediately before the stream begins and reset to
  `false` in a `finally` block (after success or error). This causes the existing
  `window.beforeunload` handler to warn the user if they try to navigate away while the
  stream is in flight, preventing a silent partial-file interruption.
- `pipeTo()` closes `fileWritable` automatically on successful completion.
- `fileWritable.abort()` discards the partial write on stream error. The file at the
  chosen location is removed or left empty depending on OS behavior.
- `_suggestedName` computed before the picker is reused as a fallback for `fname` if
  the `Content-Disposition` header is stripped by a proxy.

**SonarQube / Best-Practice Notes:**

- No hardcoded filesystem paths. Endpoint URLs (`'export_media_download.php'`) are
  relative strings consistent with all other `fetch` calls in the same function.
- `AbortError` handling matches the pattern used in the `doCreateBackup()` backup save
  path already in the file (line ~900).
- `pipeTo()` propagates backpressure automatically; no manual `flush()` or `close()`
  is needed.
- RSPEC-3776 (cognitive complexity): the streaming-only Step 4 block is strictly flatter
  than the prior streaming/Blob `if/else` design. No additional nesting is introduced.
- Progress rendering inside `TransformStream.transform()` is synchronous. The browser
  renders between chunk deliveries at typical network speeds (100 Mbps: one 256 KB chunk
  every ~20 ms). On very fast local networks, progress updates may accumulate faster than
  the browser can paint between them — this is a minor cosmetic issue only, not a
  correctness concern.

**Verification:**

- **Chrome (streaming path):** Click "Download Archive" on a populated instance. Confirm
  the size/count dialog appears, followed immediately by the OS save-file picker with the
  suggested filename pre-filled. After picking a location, confirm the build progress bar
  updates, then the streaming download progress bar updates, then the file appears at the
  chosen location at its full expected size.
- **Chrome — picker dismissed:** Click "Download Archive", confirm the size dialog, then
  cancel the file picker. Confirm the status shows "Canceled." and the button re-enables.
  Confirm no worker was spawned (no job directory in `/tmp/gighive_export_*` on the
  server and no new entry in `worker.log`).
- **Firefox / Safari (hard block):** Load the page in Firefox or Safari. Click "Download
  Archive". Confirm an error message appears immediately ("requires Chrome or Edge 86+"),
  the button re-enables, and no server request is made (no new entry in `worker.log`).
- **Chrome — stream error simulation:** In devtools, block `export_media_download.php`
  mid-stream. Confirm "Stream error:" appears in the status, the button re-enables, and
  the partial file is discarded.

### Step 4 — Add `addInitScript` mock to the existing Playwright regression test

> **Blocker: this step must be committed in the same change as Step 3.**
>
> Playwright runs Chromium, which supports `showSaveFilePicker`. After Step 3 ships, the
> existing "Admin pages full regression" test clicks `#exportMediaBtn`, `page.on('dialog')`
> accepts the `window.confirm()`, and then the OS native file picker appears.
> `page.on('dialog')` has no effect on native OS dialogs — the test hangs indefinitely,
> the 30-second `#exportMediaStatus` timeout fires, and all 12 subsequent steps fail.

**File:** `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`

Now that Firefox/Safari are hard-blocked rather than falling back to Blob, the previous
approach of deleting `showSaveFilePicker` no longer works — it would trigger the
unsupported-browser error and fail the test immediately.

Instead, replace `showSaveFilePicker` with a mock that returns a fake
`FileSystemFileHandle` whose `createWritable()` returns a discarding `WritableStream`.
The streaming code path runs end-to-end in Playwright but writes bytes nowhere:

```typescript
test('Admin pages full regression — all 13 steps', async ({ page }) => {
  // Mock showSaveFilePicker: return a fake handle whose writable stream discards data.
  // This lets the full streaming code path run in Playwright (Chromium) without
  // triggering the real OS file picker dialog, which page.on('dialog') cannot intercept.
  await page.addInitScript(() => {
    (window as any).showSaveFilePicker = async () => ({
      createWritable: async () => new WritableStream({
        write(_chunk) {},   // discard all bytes
        close() {},
        abort() {}
      })
    });
  });

  page.on('dialog', dialog => dialog.accept());
  // ... remainder of existing test unchanged ...
```

`addInitScript` runs before any page script. `doExportMedia()` sees `showSaveFilePicker`
as present, calls it, receives the fake handle, streams the archive into the discarding
`WritableStream`, and sets `steps[2] = ok`. `#exportMediaStatus` becomes non-empty and
the `not.toBeEmpty({ timeout: 30_000 })` assertion passes.

- [x] **Step 4** — Add `addInitScript` mock to "Admin pages full regression" in `admin-pages.spec.ts`. Commit in the same change as Step 3.

**Verification:** Run the full Playwright suite immediately after committing Steps 3 and 4.
Step 2 of the regression (Section E export) must complete without timing out. All 13 steps
must pass.

---

### Step 5 — Fix `window.confirm()` local-path text

**File:** `admin_system.php`
**Location:** `exportRun()` — the `confirmMsg` ternary at line 1308.

The current local-path branch reads:

```javascript
'You are about to zip ' + fmtBytes(totalBytes) + ' of files.' + skippedWarn
+ '\n\nMake sure you have enough free space to accommodate this download.'
+ '\n\nDo you wish to continue?'
```

Two problems:
1. "zip" is stale — the archive is `tar.gz` since the prior migration.
2. "Make sure you have enough free space to accommodate this download" is inaccurate for
   the streaming path: the archive streams directly to the chosen location and never
   sits as a full blob in the default Downloads folder.

Replace the local-path branch with:

```javascript
'You are about to export ' + fmtBytes(totalBytes) + ' of files as a tar.gz archive.'
+ skippedWarn
+ '\n\nDo you wish to continue?'
```

The Azure branch (`'You are about to upload...'`) is unchanged.

**SonarQube / Best-Practice Notes:** Text-only change inside an existing JS string. No
new logic, no element IDs, no event handlers.

**Verification:** Click "Download Archive" on a populated instance in a local-dest
configuration. Confirm the confirm dialog reads "You are about to export … of files as a
tar.gz archive." Confirm the Azure path confirm text is unchanged.

- [x] **Step 5** — Update the `window.confirm()` local-path string in `exportRun()`.

---

### Step 6 — Add compatibility notice test (new standalone test)

Add the following test before or after the existing "Create backup" test. It is
non-destructive and safe to run on any environment at any time:

```typescript
test('Section E — browser compatibility notice visible', async ({ page }) => {
  await page.goto('/admin/admin_system.php');
  const sectionE = page.locator('.section-divider').filter({ hasText: 'Export Media Archive' });
  await expect(sectionE).toContainText('Chrome');
  await expect(sectionE).toContainText('Edge 86');
});
```

**Verification:** Run the Playwright suite. The new test must pass. No other tests are
affected by this addition.

---

### Step 7 — Update `process_admin_export_media.md`

**File:** `process_admin_export_media.md` (gighiveapp repo root)

The document created alongside the current conversation describes the download flow using
the current chunk-accumulate-Blob behavior. Once Steps 3–5 ship, update the following
sections of that doc to reflect the streaming behavior:

- **Step 4 description** — replace the Blob-accumulation bullet list with a description of
  the streaming path (Chrome/Edge 86+ required; data piped directly to a user-chosen file).
- **Browser memory section** — remove the "archive must fit in browser memory" statement;
  replace with a note that the streaming path has no browser RAM ceiling, and that
  Firefox/Safari cannot use this tool — rsync or direct volume backup instead.
- **Large-library guidance** — note that Chrome/Edge 86+ can now download archives of any
  size; rsync or direct volume backup is still recommended for consistently very large
  libraries even on Chrome due to midstream failure risk.

This is a documentation-only change. No PHP, Ansible, or test files are involved.

- [x] **Step 7** — Update `process_admin_export_media.md` to document the streaming behavior
  and adjust the browser-memory constraint to apply only to the Blob fallback path.

---

### Step 8 — Add unsupported-browser hard block test

**File:** `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`

This test simulates a browser without `showSaveFilePicker` and verifies that clicking the
button produces the hard block error immediately — with no server call and no hanging:

```typescript
test('Section E — unsupported browser shows hard block error', async ({ page }) => {
  // Simulate Firefox/Safari: remove showSaveFilePicker before the page loads.
  await page.addInitScript(() => { delete (window as any).showSaveFilePicker; });
  await page.goto('/admin/admin_system.php');
  await page.click('#exportMediaBtn');
  // Error must appear immediately — no server round-trip, no 30s wait.
  await expect(page.locator('#exportMediaStatus')).toContainText('Chrome or Edge 86+', { timeout: 2000 });
  // Button must re-enable without any dialog or delay.
  await expect(page.locator('#exportMediaBtn')).toBeEnabled({ timeout: 2000 });
});
```

Non-destructive — no worker is spawned, no job directory is created. Safe to run on any
environment at any time.

- [x] **Step 8** — Add hard block test to `admin-pages.spec.ts`.

**Verification:** Run the Playwright suite. The new test must pass within 2 seconds of
clicking the button. No `worker.log` entry should appear on the server.

---

### Step 9 — Add `/tmp` free-space guard to `export_media.php` prepare mode

**File:** `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/export_media.php`
**Location:** Inside the `if ($mode === 'prepare')` block, after the `$found === 0` guard
(i.e., between the "No media files found" exit and the success `json_encode` response).
Scoped to `$destination === 'local'` only — the Azure upload path is unaffected.

```php
// /tmp free-space guard — local destination only.
// disk_free_space() returns false on failure; fail safe open (do not block if unavailable).
if ($destination === 'local') {
    $tmpFree = disk_free_space(sys_get_temp_dir());
    if ($tmpFree !== false && (int)$tmpFree < $totalBytes) {
        http_response_code(507);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Insufficient server temp space: '
                       . round((int)$tmpFree / 1048576) . ' MB available, '
                       . round($totalBytes / 1048576) . ' MB required. '
                       . 'Use rsync or direct volume backup for this library size.',
        ]);
        exit;
    }
}
```

**Design notes:**

- `sys_get_temp_dir()` is the same call the worker uses for `$jobDir` — no hardcoded path.
- `disk_free_space()` returns `false` on permission errors or unsupported filesystems.
  The guard only fires on a real numeric result that is below threshold. On failure, the
  export proceeds and a mid-build `tar` failure is the fallback signal.
- `round($n / 1048576)` gives MB figures; sufficient precision for an error message.
- HTTP 507 "Insufficient Storage" is the semantically correct status for this condition.
- The error message is surfaced by the existing prepare-error path in `exportRun()` — no
  JS change required:
  ```javascript
  if (!prepResp.ok || !(prepData && prepData.success)) {
      const msg = (prepData && (prepData.error || prepData.message)) ? ...
      steps[0] = { name: 'Query database', status: 'error', message: msg };
  ```
  The 507 causes `!prepResp.ok`, the JSON error text becomes `msg`, and it is rendered
  in the "Query database" step status.

**Azure path — confirmed no guard needed:**
`export_media_worker_azure.php` uploads files directly from the media volumes
(`/var/www/html/audio`, `/var/www/html/video`) to Azure Blob Storage via
`uploadBlobFromFile()`. No intermediate `archive.tar.gz` is written to `/tmp`.
The Azure job directory contains only small JSON status files (negligible size).
No `/tmp` space guard is needed for `$destination === 'azure'`.
If the Azure worker's behavior changes in the future, this should be re-evaluated.

**SonarQube / Best-Practice Notes:**
- No hardcoded paths. Uses `sys_get_temp_dir()`.
- `disk_free_space()` is a standard PHP built-in; no shell exec or regex required.
- Fail-safe-open on `false` return — never blocks a legitimate export due to a permission
  or filesystem quirk.

**Verification:**
- Cannot safely simulate a full `/tmp` on a shared dev VM. Use the Step 11 Playwright
  route-mock test to verify the client-side error display path end-to-end.
- Manual spot-check: on devvm, confirm a normal export still succeeds after deploy (proves
  the guard does not fire spuriously when space is adequate).

- [x] **Step 9** — Add `disk_free_space` guard to `export_media.php`.

---

### Step 10 — Update `process_admin_export_media.md` with transfer-flow note

**File:** `gighiveapp/process_admin_export_media.md` (gighiveapp repo root)
**Location:** After the closing paragraph of the "Step 7 — Browser streams the archive
directly to disk" section (after "…the export must be restarted from scratch."), before
the `---` separator.

Add the following two lines as a standalone paragraph:

> **Transfer flow:** (server) full `archive.tar.gz` built in `/tmp` → streamed over HTTP → (client) written directly to chosen location on disk.
>
> This script checks available space in server `/tmp` before the export starts and shows an error if there is insufficient space. Available space on the client destination cannot be checked from the browser — ensure you have sufficient free space at your chosen save location before starting a large export.

**Notes:**
- The first sentence is the explicit flow diagram the user requested.
- The second sentence is accurate only after Step 9 is deployed. These two steps should
  ship together in the same Ansible deploy.
- No PHP, JS, or Ansible changes — documentation only.

- [x] **Step 10** — Add transfer-flow note and client-space caveat to `process_admin_export_media.md`.

---

### Step 11 — Add Playwright route-mock test for the space-check error

**File:** `gighiveinfra/ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`

Add after the "unsupported browser" test (Step 8). Uses `page.route()` to intercept the
prepare `POST` and return a 507, without spawning a worker or touching the server:

```typescript
test('Section E — server temp space error shown in UI', async ({ page }) => {
  // Simulate export_media.php prepare returning HTTP 507 (Insufficient Storage).
  // Intercept only the prepare call; pass through everything else.
  await page.route('**/export_media.php', async (route, request) => {
    const body = request.postData() ?? '';
    if (body.includes('mode=prepare')) {
      await route.fulfill({
        status: 507,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          error: 'Insufficient server temp space: 50 MB available, 200 MB required. '
               + 'Use rsync or direct volume backup for this library size.'
        })
      });
    } else {
      await route.continue();
    }
  });
  await page.goto('/admin/admin_system.php');
  await page.click('#exportMediaBtn');
  // Error must appear in the Query database step — no dialog, no worker.
  await expect(page.locator('#exportMediaStatus')).toContainText('Insufficient server temp space', { timeout: 5000 });
  await expect(page.locator('#exportMediaBtn')).toBeEnabled({ timeout: 2000 });
});
```

Non-destructive — no worker is spawned, no job directory is created. Safe to run on any
environment at any time. The `page.route()` is scoped to this test only and does not
affect the regression test or other tests in the suite.

- [x] **Step 11** — Add route-mock test to `admin-pages.spec.ts`.

**Verification:** Run the Playwright suite. The new test must surface the error string in
`#exportMediaStatus` within 5 seconds and the button must re-enable. No `worker.log`
entry should appear on the server.

---

### Step 14 — Byte-based progress in `export_media_worker.php`

**File:** `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/export_media_worker.php`

**Root cause confirmed:** The verbose callback increments a file counter (`$verboseCount`). On a library with large video files followed by small thumbnails, 54% of files by count = 90%+ of bytes. The sub-line shows misleading counts while the progress bar percentage is equally wrong.

**What the tar verbose output emits** (confirmed from `runTar()` in `admin_media_lib.php`):
- Audio files: bare filename relative to `-C /var/www/html/audio`, e.g. `abc123...def.mp3`
- Video files: bare filename relative to `-C /var/www/html/video`, e.g. `abc123...def.mp4`
- Thumbnails: `thumbnails/abc123...def.png` (relative to the same video `-C` root)

These match exactly the keys already being assembled into `$audioFiles`, `$videoFiles`, and `$thumbnailFiles` arrays.

**Change — build `$fileSizeMap` during the file scan loop** (the `foreach ($rows as $row)` block that already calls `filesize()`):

```php
$fileSizeMap = [];  // added alongside existing $audioFiles / $videoFiles arrays

// inside the foreach, after existing $audioFiles[] / $videoFiles[] pushes:
if ($type === 'audio') {
    $audioFiles[]           = $filename;
    $fileSizeMap[$filename] = (int)filesize($filePath);      // same filesize() already called
} else {
    $videoFiles[]           = $filename;
    $fileSizeMap[$filename] = (int)filesize($filePath);
    $thumbRel               = 'thumbnails/' . $sha . '.png';
    $thumbPath              = $videoDir . '/' . $thumbRel;
    if (is_file($thumbPath)) {
        $thumbnailFiles[]         = $thumbRel;
        $fileSizeMap[$thumbRel]   = (int)filesize($thumbPath);
        $bytesAdded              += (int)filesize($thumbPath);
    }
}
$bytesAdded += (int)filesize($filePath);
```

**Change — track bytes in the verbose callback:**

```php
$verboseCount   = 0;
$bytesProcessed = 0;      // NEW

$result = runTar($tarArgs, null, [], static function (string $line)
    use (&$verboseCount, &$bytesProcessed, $added, $skipped, $bytesAdded, $jsonPath, $jobId, $fileSizeMap): void {
    if ($line === '') return;
    $verboseCount++;
    $bytesProcessed += $fileSizeMap[$line] ?? 0;             // NEW

    writeJobStatus($jsonPath, [
        'success'         => true,
        'job_id'          => $jobId,
        'state'           => 'running',
        'updated_at'      => date('c'),
        'processed'       => $verboseCount,
        'total'           => $added,
        'added'           => $verboseCount,
        'skipped'         => $skipped,
        'bytes_added'     => 0,
        'steps'           => [
            ['name' => 'Build archive', 'status' => 'running',
             'message'  => $verboseCount . ' / ' . $added . ' files',    // "files" not "written"
             'progress' => [
                 'processed' => $bytesProcessed,                          // CHANGED: bytes
                 'total'     => $bytesAdded,                              // CHANGED: bytes
                 'unit'      => 'bytes',                                  // NEW: signal for renderer
             ]],
        ],
    ]);
});
```

**Design notes:**
- `$fileSizeMap` is built during the existing scan — no extra `filesize()` syscalls.
- `$fileSizeMap[$line] ?? 0` is safe: if tar somehow emits an unexpected line, bytes just
  don't advance; the percentage stays conservative rather than crashing.
- Top-level `processed` / `total` remain file counts — used by `pollJobStatus` callers that
  may read them independently. No regression there.
- `message` changes from `"195 / 1199 written"` to `"195 / 1199 files"` — clearer wording,
  count still visible in the text while the progress bar shows byte-accurate %.
- The `unit: 'bytes'` field is ignored by all existing renderers (Step 15 adds the check).
  No regression on other sections that use `pollJobStatus` / `renderImportStepsShared`.

- [ ] **Step 14** — Update `export_media_worker.php`.

---

### Step 15 — Byte-aware sub-line in `import_progress.js`

**File:** `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/assets/import_progress.js`

**Root cause in renderer** — line 144:
```javascript
+ processed + ' / ' + total + ' (' + pct + '%)' + indicator
```
Raw integers are concatenated. With bytes, this produces `70378668032 / 140056775321 (50%)`.

**Add `_fmtBytes()` helper** (follows the identical pattern already used in `admin_system.php`'s
`__fmtBytes` and per-section `fmtBytes`):

```javascript
function _fmtBytes(n) {
  if (n >= 1073741824) return (n / 1073741824).toFixed(1) + ' GB';
  if (n >= 1048576)    return (n / 1048576).toFixed(1) + ' MB';
  if (n >= 1024)       return (n / 1024).toFixed(1) + ' KB';
  return n + ' B';
}
```

**Update the sub-line render** (line 144) to check `progress.unit`:

```javascript
// Existing:
+ processed + ' / ' + total + ' (' + pct + '%)' + indicator

// Replacement:
+ (progress.unit === 'bytes'
    ? _fmtBytes(processed) + ' / ' + _fmtBytes(total)
    : processed + ' / ' + total)
+ ' (' + pct + '%)' + indicator
```

**Design notes:**
- Change is 4 lines in `import_progress.js`. All other callers (Import Media, Azure import,
  background jobs) pass no `unit` field — they hit the existing code path unchanged.
- `getImportProgressEtaText()` operates on raw `processed`/`total` numbers regardless of
  unit — byte-based ETA is automatically more accurate than count-based ETA.
- The `_fmtBytes` function name is prefixed with `_` to match the module's internal
  convention (`_esc`, `_formatEtaFromMs`, `_poll`).

- [ ] **Step 15** — Update `import_progress.js`.

---

### Step 16 — Playwright assertion for byte-formatted progress

**File:** `gighiveinfra/ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`

The regression test already waits for `Export complete` on the `alert-ok` banner (Step 13).
Add an assertion immediately after that checks the "Build archive" sub-line shows a
byte-formatted value, not a raw integer:

```typescript
// After the existing Export complete assertion:
await expect(page.locator('#exportMediaStatus'))
  .toContainText(/\d+\.\d+ (MB|GB) \/ \d+\.\d+ (MB|GB)/, { timeout: 5000 });
```

This regex matches any `X.Y MB / A.B MB` or `X.Y GB / A.B GB` pattern, confirming the
renderer selected the bytes branch. It does not assert a specific value (devvm dataset size
may vary).

**Design notes:**
- The assertion is on `#exportMediaStatus` as a whole — the byte-formatted sub-line text
  is present in the rendered HTML inside that container.
- Timeout of 5000 ms is sufficient: by the time `Export complete` appears (Step 13
  assertion), the archive step is already rendered in its `ok` state.

- [ ] **Step 16** — Add byte-format assertion to `admin-pages.spec.ts`.

---

### Step 17 — Byte-based progress in `import_media_zip_worker.php`

**File:** `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/import_media_zip_worker.php`

Both the ZIP and tar.gz branches emit file-count-based `step.progress`. The same skew as the export worker occurs when large video files finish early and small thumbnails remain.

The `unit: 'bytes'` field added in Step 15 to `import_progress.js` already handles rendering — only the PHP payload needs to change.

---

#### ZIP branch (lines ~88–202)

**Pre-scan** — accumulate `$totalBytes` alongside the existing `$total++`:

```php
$totalBytes = 0;           // ADD alongside existing variable declarations

// Inside the pre-scan for loop, after isValidMediaEntry check:
if (isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
    $total++;
    $totalBytes += (int)($stat['size'] ?? 0);   // ADD
}
```

**Main loop** — move `$bytesProcessed` accumulation before the file-exists branch so it always fires (matches how `$processed` increments unconditionally):

```php
$processed++;
$bytesProcessed += (int)($stat['size'] ?? 0);   // ADD — before the is_file check
```

**`writeJobStatus` calls** — change `step.progress` in both the running-state update and the initial-state write:

```php
'progress' => ['processed' => $bytesProcessed, 'total' => $totalBytes, 'unit' => 'bytes'],
```

Keep `step.message` as file counts: `"$processed / $total files imported"` — count stays readable in the text line while the bar is byte-accurate.

The final DONE-state write stays as `['processed' => $total, 'total' => $total]` (no `unit`) because the bar is already at 100% and the status is `'ok'` — the renderer latches the badge regardless.

---

#### tar.gz branch (lines ~207–390)

**Pre-scan** — the listing loop already parses `$size`; accumulate `$totalBytes`:

```php
$totalBytes = 0;   // ADD alongside existing variable declarations

// Inside the listing foreach, after isValidMediaEntry / isValidThumbnailEntry check:
if (isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
    $total++;
    $totalBytes += $size;       // ADD
} elseif (isValidThumbnailEntry($name)) {
    $total++;
    $totalBytes += $size;       // ADD
}
```

**Initial status write** — update `step.progress` to include bytes and unit (same pattern as ZIP branch above).

**Media file loop** (`foreach (glob($extractDir . '*') ...)`):

Move `$fileBytes` calculation before the `is_file` check so it is available for the already-exists path too, and add `$bytesProcessed`:

```php
$processed++;
$fileBytes = (int)filesize($filePath);          // MOVE up from inside the else branch
$bytesProcessed += $fileBytes;                  // ADD

$type = ...;
$dest = ...;

if (is_file($dest)) {
    $alreadyExists++;
    @unlink($filePath);
} else {
    if (!copy($filePath, $dest)) { ... }        // $fileBytes already available
    @unlink($filePath);
    $added++;
    $bytesAdded += $fileBytes;
}
```

**Thumbnail second-pass loop** (`foreach (glob($extractDir . 'thumbnails/*.png') ...)`):

Same refactor — move `$fileBytes = (int)filesize($thumbFilePath)` before the `is_file` check and add `$bytesProcessed += $fileBytes`.

**`writeJobStatus` in both loops** — emit `unit: 'bytes'` in `step.progress` (same pattern).

**Design notes:**
- `$totalBytes` from the pre-scan uses the size column from `tar -tzvf` output (uncompressed size). `$fileBytes` from `filesize()` post-extraction is also uncompressed. These will match for audio/video files regardless of gzip compression on the outer archive. ✓
- The tar.gz extraction itself (`tar -xzvf` without verbose callback) still has zero per-file progress updates during extraction — that is a pre-existing limitation, not introduced here. The byte-based counter kicks in during the copy-to-destination phase.
- `$bytesProcessed` is declared at line ~57 alongside the other accumulators.

- [ ] **Step 17** — Update `import_media_zip_worker.php` (both branches).

---

### Step 18 — Byte-based progress in `import_media_zip_worker_azure.php`

**File:** `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/import_media_zip_worker_azure.php`

The azure worker already has `$size = (int)($blob['size'] ?? 0)` per blob from the bloblist (line 99). No new I/O is needed.

**Pre-pass for `$totalBytes`** — add immediately after `$total = count($rows)`:

```php
$totalBytes = 0;
foreach ($rows as $blob) {
    $totalBytes += (int)($blob['size'] ?? 0);
}
```

This intentionally mirrors `$total = count($rows)` — both include all blobs, valid or not. Blobs that fail the validation conditions in the main loop receive `continue` before `$processed++`, so they don't advance `$bytesProcessed` either. The small over-count in `$totalBytes` is the same existing pattern as the over-count in `$total`.

**Main loop** — add `$bytesProcessed` after `$processed++` (line 116):

```php
$processed++;
$bytesProcessed += $size;    // ADD — $size already declared on line 99
```

**`writeJobStatus`** — emit `unit: 'bytes'`:

```php
'progress' => ['processed' => $bytesProcessed, 'total' => $totalBytes, 'unit' => 'bytes'],
```

Keep `step.message` as `"$processed / $total files imported"`.

Final DONE-state write stays as `['processed' => $total, 'total' => $total]` (no `unit`) — same reasoning as Step 17.

**Design note:** `$size` comes from Azure Blob Storage metadata and represents the blob's stored size (compressed if the blob was uploaded as-is). For GigHive exports, audio/video files are already compressed, so blob size ≈ uncompressed size. Thumbnails are PNGs which may be slightly larger in the blob than their extracted size, but the discrepancy is negligible for a progress bar. ✓

- [ ] **Step 18** — Update `import_media_zip_worker_azure.php`.

---

### Step 19 — Playwright assertion for import byte-formatted progress

**File:** `gighiveinfra/ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`

The existing import regression test verifies the import completes. Add one assertion that the "Import files" step sub-line shows a byte-formatted value, confirming the renderer took the `unit === 'bytes'` branch:

```typescript
// After the existing import success assertion:
await expect(page.locator('#importZipStatus'))
  .toContainText(/\d+\.\d+ (MB|GB) \/ \d+\.\d+ (MB|GB)/, { timeout: 5000 });
```

Element ID confirmed from `admin_system.php` line 584: `<div id="importZipStatus"></div>`.

**Design note:** The 5000 ms timeout is sufficient because by the time the import success state is confirmed, the "Import files" step is already rendered in its `ok` state with final byte values in the sub-line.

- [ ] **Step 19** — Add import byte-format assertion to `admin-pages.spec.ts`.

---

### Final Verification — Ansible Playbook Runs

After all eleven steps are complete, verification is a two-stage Ansible playbook sequence
run from pop-os against `devvm.gighive.internal`:

**Run 1 — Deploy the code:**
```
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml
```
This pushes the updated `admin_system.php` to the container. After it completes, open
`https://devvm.gighive.internal/admin/admin_system.php` in Chrome and manually verify
Steps 1–5 (UI notice, radio label, streaming download behavior, confirm text).

**Run 2 — Playwright test suite:**
```
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --tags playwright
```
This runs the full `playwright_admin_tests` suite on `devvm.gighive.internal`. All tests
must pass, including the modified regression test (Step 4 mock) and the two new standalone
tests (Steps 6 and 8). No test may time out or produce a native OS file picker dialog.

---

## UX Considerations

**Picker placement (before the build starts):** `showSaveFilePicker` requires a live user
activation. After the first `await fetch()` in `exportRun()`, the button-click activation
is consumed. A fresh activation is granted when `window.confirm()` returns true (the user
clicking OK is itself a user gesture). Calling the picker immediately after confirm — before
the start fetch — is the only viable placement that does not require an extra button click.
The user picks their save location before the build starts. For large exports where the
build may take minutes, this is better UX: the user is not left wondering where the file
will appear after a long wait.

**Canceling the picker:** If the user dismisses the picker after seeing the size confirm,
the build is not started. No server temp directory is created, no worker is spawned. The
status reverts to "Canceled." and the button re-enables immediately. This is an improvement
over the current Blob path, where clicking "Download Archive" starts the worker immediately
after confirm regardless of what the user does next.

**Unsupported browser behavior:** Firefox and Safari users see an error message on button
click before any server request is made. The message names Chrome/Edge 86+ as the
requirement and directs them to rsync or direct volume backup. No partial state, no server
resources consumed.

**Azure + local destination:** The "Download to browser" radio label is annotated only
on the local destination radio, not on the Azure radio. The Azure path is fully unaffected
by this refactor.

---

## Wireframe

```
STATE A — default (Chrome/Edge 86+)
┌─────────────────────────────────────────────────────────────────────────┐
│ Section E: Export Media Archive                                          │
│                                                                          │
│ Download a tar.gz archive of media files currently on disk...            │
│ Requires Chrome or Edge 86+ — on other browsers this download is not   │ <- NEW
│ available; use rsync or direct volume backup instead.                   │ <- NEW
│ This is a streaming copy — if interrupted, restart from scratch.        │ <- existing
│ Always create a database backup (Section C) at the same time.           │
│                                                                          │
│ Band / Event filter  [_______________________]                           │
│ File type            [All (audio + video)  ▼]                            │
│                                                                          │
│ [Azure only] Destination                                                 │
│   ◉ Download to browser (tar.gz) — Chrome/Edge 86+ required            │ <- NEW (changed)
│   ○ Send to Azure Blob Storage                                           │
│                                                                          │
│ [ Download Archive ]  <- enabled                                         │
└─────────────────────────────────────────────────────────────────────────┘

STATE A2 — default (Firefox / Safari / unsupported browser)
┌─────────────────────────────────────────────────────────────────────────┐
│ Section E: Export Media Archive                                          │
│                                                                          │
│ [same description as above — requirement notice clearly visible]         │
│                                                                          │
│ [ Download Archive ]  <- enabled (browser check happens on click)        │
└─────────────────────────────────────────────────────────────────────────┘

STATE B — unsupported browser (immediately on button click; no server request)
┌─────────────────────────────────────────────────────────────────────────┐
│  This download requires Chrome or Edge 86+. On other browsers, use      │
│  rsync or direct volume backup instead.                                  │
│                                                                          │
│ [ Download Archive ]  <- re-enabled                                      │
└─────────────────────────────────────────────────────────────────────────┘

STATE C — picker shown (Chrome/Edge 86+; immediately after confirm)
┌─────────────────────────────────────────────────────────────────────────┐
│  [OS save-file picker dialog]                                            │
│  Save As: gighive_export_all_20260904_123456.tar.gz                      │
│  [User picks a folder and clicks Save]                                   │
└─────────────────────────────────────────────────────────────────────────┘

STATE D — building archive (picker already dismissed)
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ↻ Build archive    7 / 12 written                             │
│                     [██████████████░░░░░░░░░░░░] 58%                   │
│           ○ Download         (pending)                                  │
│                                                                          │
│ [ Building archive... ]  <- disabled                                     │
└─────────────────────────────────────────────────────────────────────────┘

STATE E — streaming to disk
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ✓ Build archive    12 media file(s) + 12 thumbnail(s) written │
│           ↻ Download         245.3 MB / 941.9 MB                       │
│                     [████████████░░░░░░░░░░░░░░░░░] 26%                │
│                                                                          │
│ [ Building archive... ]  <- disabled                                     │
└─────────────────────────────────────────────────────────────────────────┘

STATE F — complete
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ✓ Build archive    12 media file(s) written                   │
│           ✓ Download         gighive_export_all_20260904_123456.tar.gz  │
│                              (941.9 MB)                                  │
│                                                                          │
│ [ Download Archive ]  <- re-enabled                                      │
└─────────────────────────────────────────────────────────────────────────┘

STATE G — picker dismissed by user (worker not spawned)
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ○ Build archive    Canceled.                                  │
│           ○ Download                                                    │
│                                                                          │
│ [ Download Archive ]  <- re-enabled                                      │
└─────────────────────────────────────────────────────────────────────────┘

STATE H — stream error mid-download
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ✓ Build archive    12 media file(s) written                   │
│           ✗ Download         Stream error: network interrupted          │
│                              (partial file at chosen location discarded) │
│                                                                          │
│ [ Download Archive ]  <- re-enabled                                      │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Files Under Change

### Modified

1. `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/admin_system.php`
   (gighiveinfra repo) — Steps 1–2: update Section E `<p class="muted">` description to
   state Chrome/Edge 86+ requirement and name rsync/direct volume backup as alternatives;
   annotate the "Download to browser (tar.gz)" radio label inside the `$__azure_available`
   block. Steps 3–5: add unsupported-browser hard block at top of `doExportMedia()`;
   replace the Step 4 download block with the streaming-only path (no Blob fallback);
   fix `window.confirm()` local-path text.

2. `gighiveinfra/ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`
   (gighiveinfra repo) — Step 4: add `page.addInitScript` mock (fake `showSaveFilePicker`
   returning a discarding `WritableStream`) to the existing export step so the full
   streaming code path runs in CI without triggering the native OS file picker. Steps 6
   and 8: add standalone compatibility notice test and unsupported-browser hard block test.

3. `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/export_media.php`
   (gighiveinfra repo) — Step 9: add `disk_free_space(sys_get_temp_dir())` guard in
   prepare mode for `destination === 'local'`; returns HTTP 507 with MB figures if
   server `/tmp` has insufficient space for the archive.

4. `process_admin_export_media.md` (gighiveapp repo root) — Step 7: update Step 7
   description, browser-memory section, and large-library guidance to reflect the
   streaming download path. Step 10: add transfer-flow one-liner and client-side space
   caveat after the Step 7 streaming description.

5. `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/assets/import_progress.js`
   (gighiveinfra repo) — Step 15: add `_fmtBytes()` helper; update sub-line render to
   format bytes when `progress.unit === 'bytes'`.
6. `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/import_media_zip_worker.php`
   (gighiveinfra repo) — Step 17: add `$totalBytes`/`$bytesProcessed` tracking in both
   zip and tar.gz branches; emit `unit: 'bytes'` in `step.progress`.
7. `gighiveinfra/ansible/roles/docker/files/apache/webroot/admin/import_media_zip_worker_azure.php`
   (gighiveinfra repo) — Step 18: add `$totalBytes` pre-pass; track `$bytesProcessed`
   per blob; emit `unit: 'bytes'` in `step.progress`.

### Unchanged

- `admin/export_media.php` start mode — only prepare mode gains the space guard
- `admin/export_media_status.php` — passes through `status.json` unchanged
- `admin/export_media_download.php` — streaming logic unchanged
- `admin/export_media_worker.php` — archive build logic unaffected
- `admin/export_media_download.php` — server-side 256 KB chunk streaming already correct
- `admin/export_media_status.php` — polling endpoint unaffected
- `admin/export_media_worker_azure.php` — Azure path does not involve browser download
- All other PHP files, Ansible roles, group_vars, Docker images, schema — unchanged

---

## Progress

### Completed

- [x] **Step 1** — Section E description: state Chrome/Edge 86+ requirement, name rsync/direct volume backup as alternatives
- [x] **Step 2** — Azure radio label: annotate "Download to browser" with Chrome/Edge requirement
- [x] **Step 3** — Add unsupported-browser hard block (Change A0); replace Step 4 with streaming-only path — no Blob fallback (`createWritable()` error handling, `_activeJob` flag)
- [x] **Step 4** — Add `addInitScript` mock to Playwright regression test — **must commit with Step 3**
- [x] **Step 5** — Fix `window.confirm()` local-path text: "zip" → "archive", remove stale "free space" sentence
- [x] **Step 6** — Add Playwright compatibility notice test
- [x] **Step 7** — Update `process_admin_export_media.md` download-path description
- [x] **Step 8** — Add Playwright unsupported-browser hard block test
- [x] **Step 9** — Add `disk_free_space` guard to `export_media.php` prepare mode (local path only): HTTP 507 if server `/tmp` space is insufficient
- [x] **Step 10** — Add transfer-flow one-liner and client-side space caveat to `process_admin_export_media.md`
- [x] **Step 11** — Add Playwright route-mock test: simulate 507 prepare response, verify error in `#exportMediaStatus` and button re-enables
- [x] **Step 12** — Add `alert-ok` success banner to local download path in `admin_system.php` (matches Azure pattern)
- [x] **Step 13** — Strengthen regression test Step 2 assertion to `toContainText('Export complete')` on `.alert-ok` with 300 s timeout
- [x] **Step 14** — `export_media_worker.php`: build `$fileSizeMap`, track `$bytesProcessed` in verbose callback, emit `unit: 'bytes'` in `step.progress`
- [x] **Step 15** — `import_progress.js`: add `_fmtBytes()` helper and check `progress.unit === 'bytes'` when rendering the sub-line
- [x] **Step 16** — `admin-pages.spec.ts`: add assertion that the "Build archive" sub-line contains a byte-formatted value (MB/GB) after export completes
- [x] **Step 17** — `import_media_zip_worker.php`: both zip and tar.gz branches — add `$totalBytes`/`$bytesProcessed` tracking; emit `unit: 'bytes'` in `step.progress`
- [x] **Step 18** — `import_media_zip_worker_azure.php`: add `$totalBytes`/`$bytesProcessed` tracking; emit `unit: 'bytes'` in `step.progress`
- [x] **Step 19** — `admin-pages.spec.ts`: add assertion that the "Import files" sub-line contains a byte-formatted value after import completes

### Remaining — This Feature

_(none — all 19 steps complete)_

### Remaining — Follow-on Tasks

_(none — self-contained PHP, documentation, and test change; no schema, Ansible roles, group_vars, or iOS scope)_
