# Refactor: Export Media — Streaming Browser Download

## Status — 2026-09-04

Pending — planning phase. No implementation started.

---

## Elevator Pitch

When you export media files from the admin panel, the current download works by loading
the entire archive into the browser's memory before saving it to your computer. For large
libraries this crashes the browser tab before the file ever reaches your disk. This
refactor makes the download pipe data directly from the server to a file you choose on
your disk — no matter how large the archive — exactly the way modern cloud storage
services handle large downloads. It also adds a clear note in the admin UI about which
browsers support the improved method.

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
write using the File System Access API. Preserve the per-byte progress bar on both paths.
Fall back to the existing Blob approach on browsers that do not support
`showSaveFilePicker`. Add a browser compatibility notice to the Section E UI.

**Policy: After this refactor, a Section E export on Chrome or Edge 86+ must never
require the full archive to fit in browser RAM.**

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

**Primary path (Chrome and Edge 86+):** After the user confirms the size dialog,
immediately call `showSaveFilePicker` (while still within the user gesture context) to
obtain a `FileSystemFileHandle`. Build the archive on the server. When the build completes,
open the writable stream (`fileHandle.createWritable()`) and pipe the download response
through a `TransformStream` (for progress counting) into the file writable. No chunks are
held in memory.

**Fallback path (Firefox, Safari, older browsers):** The existing chunk-accumulate-Blob
approach is kept unchanged as the `else` branch. No regression for those browsers.

**UI notice:** A compatibility note is added to the Section E description text. When the
Azure destination radio is rendered (Azure credentials configured), the "Download to
browser" radio label is also annotated.

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
| Browser compatibility | `showSaveFilePicker` is Chrome and Edge 86+ only. Firefox and Safari use the Blob fallback, retaining the ~20 GB practical constraint on those browsers. |
| File picker before build starts | Picker dialog appears right after the size confirm, before the archive starts building. The user picks a destination before the server has finished. This is the only placement that keeps the call within the browser's user gesture window (see UX Considerations). |
| Picker dismissal cancels the job | Dismissing the file picker after the confirm dialog prevents the build from starting. No server resources are wasted — the worker is not spawned until after the picker returns. |
| Partial file on interrupted download | Blob path: nothing written until fully received (all-or-nothing). Streaming: partial file on disk if the connection drops. Both require a full restart to recover; streaming makes the interruption visible on disk. `writable.abort()` is called on error to discard the partial write. |
| Requires secure context | `showSaveFilePicker` requires HTTPS or localhost. Production GigHive is HTTPS. Local HTTP development environments fall back to Blob automatically. |

---

## Real World Use Cases

| Scenario | Before | After |
|---|---|---|
| Admin on Chrome exports 2 GB tutorial library | Works; browser temporarily holds ~2 GB in RAM | Works; data streams directly to disk — RAM near zero |
| Admin on Chrome exports 130 GB full library | Browser tab crashes with out-of-memory error | Works; picker appears after confirm dialog, data streams to chosen disk location |
| Admin on Firefox exports 5 GB library | Works (fits in RAM) | Unchanged Blob path; compatibility notice now tells admin the limit |
| Admin on Firefox exports 50 GB library | Browser tab crashes | Still crashes on Firefox; notice guides admin toward Azure or rsync for large libraries |
| Admin on Chrome, Azure configured | Blob path, no destination choice shown | Streaming path; radio label annotated with compatibility note |

---

## Design Principles

1. No regression for any browser. The Blob fallback is a byte-for-byte preservation of the current download block.
2. Progress bar works on both paths.
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

- [ ] **Phase 1, Step 1** — Add browser compatibility notice sentence to Section E `<p class="muted">` in `admin_system.php`
- [ ] **Phase 1, Step 2** — Annotate the "Download to browser (tar.gz)" radio label text inside the `$__azure_available` block
- [ ] **Phase 2, Step 3** — Replace the Step 4 download block in `doExportMedia()` with the streaming / Blob-fallback branch
- [ ] **Phase 3, Step 4** — Add standalone Playwright test verifying the compatibility notice text is present in Section E
- [ ] **Phase 3, Step 5** — Add `addInitScript` mock to neutralize `showSaveFilePicker` in the existing Playwright export regression step

---

### Phase 1 — UI Compatibility Notice

**Goal:** Make the browser download method and its constraints visible to the admin before
they start an export, without changing any interactive behaviour.

#### Step 1 — Section E description text

**File:** `admin_system.php`
**Location:** Section E `<p class="muted">` block (lines 503–509, the paragraph beginning
"Download a tar.gz archive of media files currently on disk…")

Append the following sentence to that paragraph, immediately before the closing `</p>`:

> On Chrome and Edge 86+, the archive streams directly to disk with no browser memory
> limit. On other browsers (Firefox, Safari), the archive is buffered in browser RAM before
> saving — the 20 GB guideline applies on those browsers.

This is a text-only change. No PHP logic, no element IDs, no event handlers, and no
JS references change.

#### Step 2 — Azure destination radio label

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

### Phase 2 — Streaming Download Block

**Goal:** Replace the Step 4 download block with a branch that streams directly to disk
on capable browsers and falls back to the existing Blob path on others.

#### User gesture and file picker timing

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

#### Step 3 — Replace the Step 4 download block

**File:** `admin_system.php`
**Location:** `doExportMedia()` — `exportRun()` — two insertion points within this function.

**Change A — Picker call immediately after `window.confirm()` (inside `exportRun`):**

The existing code after the `window.confirm()` block is:

```javascript
steps[1] = { name: workerStepName, status: 'running', ... };
render();
// ── Step 2: Start async worker ─────────────────────────────────────────
let startResp, startData;
startResp = await fetch('export_media.php', { ... mode: 'start' ... });
```

Insert the following block between `render()` and the `await fetch` start call:

```javascript
// ── Streaming path: obtain file handle now (inside window.confirm() gesture) ──
// showSaveFilePicker must be called before the first await fetch() that follows.
// window.confirm() returning true creates a fresh user activation in Chrome.
let _streamFileHandle = null;
let _suggestedName    = 'gighive_export.tar.gz'; // fallback; refined below
if ('showSaveFilePicker' in window) {
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
      steps[1] = { name: workerStepName, status: 'pending', message: 'Canceled.', progress: null };
      if (dest === 'local') steps[2] = { name: 'Download', status: 'pending', message: '', progress: null };
      render();
      return; // worker not spawned — no server resources consumed
    }
    _streamFileHandle = null; // non-abort error: fall back to Blob path silently
  }
}
```

**Change B — Replace the Step 4 download block with a streaming / Blob branch:**

The existing Step 4 block (from `steps[2] = { name: 'Download', status: 'running'...`
through `URL.revokeObjectURL(url)`) is replaced with the following structure:

```javascript
// ── Step 4: Download ───────────────────────────────────────────────────────
if (_streamFileHandle !== null) {
  // ── Streaming path: Chrome / Edge 86+ ────────────────────────────────────
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
  const fileWritable = await _streamFileHandle.createWritable();
  try {
    await dlResp.body.pipeThrough(progressTransform).pipeTo(fileWritable);
    // pipeTo() closes fileWritable on success automatically
  } catch (err) {
    await fileWritable.abort().catch(() => {});
    steps[2] = { name: 'Download', status: 'error', message: 'Stream error: ' + err.message };
    render();
    return;
  }

  steps[2] = { name: 'Download', status: 'ok',
               message: fname + ' (' + fmtBytes(received) + ')',
               progress: { processed: received || effectiveLength, total: effectiveLength || received } };
  render();

} else {
  // ── Blob fallback: Firefox, Safari, non-HTTPS, unsupported browsers ────────
  // Retain the existing Step 4 block verbatim — no modifications.
}
```

**Implementation notes:**

- `_streamFileHandle` is `null` when `showSaveFilePicker` is absent (Firefox, Safari)
  OR when a non-AbortError picker error occurred. Both fall through to the Blob path.
- `fileHandle.createWritable()` is called immediately before `pipeTo`. The OS file is
  created at this point — not when the picker was shown. If the build failed before
  reaching this line, no file is created at the chosen location.
- `pipeTo()` closes `fileWritable` automatically on successful completion.
- `fileWritable.abort()` discards the partial write on stream error. The file at the
  chosen location is removed or left empty depending on OS behavior.
- `_suggestedName` computed before the picker is reused as a fallback for `fname` if
  the `Content-Disposition` header is stripped by a proxy.
- The Blob fallback `else` block is the existing Step 4 code, byte-for-byte. No
  modifications are made to it.

**SonarQube / Best-Practice Notes:**

- No hardcoded filesystem paths. Endpoint URLs (`'export_media_download.php'`) are
  relative strings consistent with all other `fetch` calls in the same function.
- `AbortError` handling matches the pattern used in the `doCreateBackup()` backup save
  path already in the file (line ~900).
- `pipeTo()` propagates backpressure automatically; no manual `flush()` or `close()`
  is needed.
- RSPEC-3776 (cognitive complexity): the streaming/fallback `if/else` adds one nesting
  level to the download section of `exportRun()`. Acceptable given the function's existing
  length. No additional nesting is introduced inside either branch.
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
- **Firefox (Blob fallback):** Confirm the export proceeds exactly as before — file lands
  in the default Downloads folder, progress bar works.
- **Chrome — stream error simulation:** In devtools, block `export_media_download.php`
  mid-stream. Confirm "Stream error:" appears in the status, the button re-enables, and
  the partial file is discarded.

---

### Phase 3 — Tests

**Goal:** Cover the new UI text and protect the existing export regression step from
hanging on the native OS file picker during automated CI runs.

**Role:** `playwright_admin_tests`
**File:** `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`

#### Step 4 — Add compatibility notice test (new standalone test)

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

#### Step 5 — Neutralize `showSaveFilePicker` in the existing export regression step

The existing "Admin pages full regression" test (Step 2, line 71) clicks `#exportMediaBtn`
and asserts `#exportMediaStatus` is non-empty. Playwright runs Chromium, which supports
`showSaveFilePicker`. The OS file picker that would appear is a native dialog that
`page.on('dialog', dialog => dialog.accept())` does not intercept — the test would hang
indefinitely.

Add `page.addInitScript` at the top of the "Admin pages full regression" test to delete
`showSaveFilePicker` before any page script executes. This forces the Blob fallback path
in automated runs without affecting the streaming path in real browsers:

```typescript
test('Admin pages full regression — all 13 steps', async ({ page }) => {
  // Force Blob fallback: remove showSaveFilePicker so the OS file picker does not
  // appear and block the automated test. Real browsers retain the streaming path.
  await page.addInitScript(() => { delete (window as any).showSaveFilePicker; });

  page.on('dialog', dialog => dialog.accept());
  // ... remainder of existing test unchanged ...
```

`addInitScript` runs before any page script, so the deletion takes effect before
`doExportMedia()` checks `'showSaveFilePicker' in window`.

**Verification:** Run the full Playwright suite on `devvm.gighive.internal`. All 13 steps
of the existing regression test must pass. The new compatibility notice test must pass.
No test may hang on a native file picker dialog.

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

**Blob fallback visibility:** Firefox and Safari users see the same Blob path as today,
plus the new compatibility notice. The notice gives context for why a large export would
fail and points toward Azure Blob Storage or rsync for large libraries.

**Azure + local destination:** The "Download to browser" radio label is annotated only
on the local destination radio, not on the Azure radio. The Azure path is fully unaffected
by this refactor.

---

## Wireframe

```
STATE A — default
┌─────────────────────────────────────────────────────────────────────────┐
│ Section E: Export Media Archive                                          │
│                                                                          │
│ Download a tar.gz archive of media files currently on disk...            │
│ This tool is designed for small-to-medium libraries (under 20 GB).      │
│ On Chrome and Edge 86+, the archive streams directly to disk with no    │ <- NEW
│ browser memory limit. On other browsers (Firefox, Safari), the archive  │ <- NEW
│ is buffered in browser RAM — the 20 GB guideline applies on those       │ <- NEW
│ browsers. For libraries larger than 20 GB, rsync or direct volume        │
│ backup is recommended.                                                   │
│                                                                          │
│ Band / Event filter  [_______________________]                           │
│ File type            [All (audio + video)  ▼]                            │
│                                                                          │
│ [Azure only] Destination                                                 │
│   ◉ Download to browser (tar.gz)                                         │
│     — streams to disk on Chrome/Edge 86+; buffered in RAM on others    │ <- NEW (changed)
│   ○ Send to Azure Blob Storage                                           │
│                                                                          │
│ [ Download Archive ]  <- enabled                                         │
└─────────────────────────────────────────────────────────────────────────┘

STATE B — picker shown (Chrome/Edge 86+ only; immediately after confirm)
┌─────────────────────────────────────────────────────────────────────────┐
│  [OS save-file picker dialog]                                            │
│  Save As: gighive_export_all_20260904_123456.tar.gz                      │
│  [User picks a folder and clicks Save]                                   │
└─────────────────────────────────────────────────────────────────────────┘

STATE C — building archive (both paths; picker is already dismissed)
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ↻ Build archive    7 / 12 written                             │
│                     [██████████████░░░░░░░░░░░░] 58%                   │
│           ○ Download         (pending)                                  │
│                                                                          │
│ [ Building archive... ]  <- disabled                                     │
└─────────────────────────────────────────────────────────────────────────┘

STATE D — streaming to disk (Chrome/Edge 86+ only)
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ✓ Build archive    12 media file(s) + 12 thumbnail(s) written │
│           ↻ Download         245.3 MB / 941.9 MB                       │
│                     [████████████░░░░░░░░░░░░░░░░░] 26%                │
│                                                                          │
│ [ Building archive... ]  <- disabled                                     │
└─────────────────────────────────────────────────────────────────────────┘

STATE E — complete (both paths)
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ✓ Build archive    12 media file(s) written                   │
│           ✓ Download         gighive_export_all_20260904_123456.tar.gz  │
│                              (941.9 MB)                                  │
│                                                                          │
│ [ Download Archive ]  <- re-enabled                                      │
└─────────────────────────────────────────────────────────────────────────┘

STATE F — picker dismissed by user (Chrome/Edge 86+ only; worker not spawned)
┌─────────────────────────────────────────────────────────────────────────┐
│  Export:  ✓ Query database   12 file(s) ready (941.9 MB)               │
│           ○ Build archive    Canceled.                                  │
│           ○ Download                                                    │
│                                                                          │
│ [ Download Archive ]  <- re-enabled                                      │
└─────────────────────────────────────────────────────────────────────────┘

STATE G — stream error mid-download (Chrome/Edge 86+ only)
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
   (gighiveinfra repo) — Phase 1: add browser compatibility notice sentence to the Section E
   `<p class="muted">` description; annotate the "Download to browser (tar.gz)" radio label
   inside the `$__azure_available` block. Phase 2: insert `showSaveFilePicker` call
   immediately after `window.confirm()` in `exportRun()`; replace the Step 4 download block
   with a streaming + Blob-fallback branch.

2. `gighiveinfra/ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`
   (gighiveinfra repo) — Phase 3: add standalone compatibility notice test; add
   `page.addInitScript` `showSaveFilePicker` deletion to the existing export step in the
   full regression test to prevent hang on the native OS file picker during CI runs.

### Unchanged

- `admin/export_media.php` — prepare/start logic unaffected
- `admin/export_media_worker.php` — archive build logic unaffected
- `admin/export_media_download.php` — server-side 256 KB chunk streaming already correct
- `admin/export_media_status.php` — polling endpoint unaffected
- `admin/export_media_worker_azure.php` — Azure path does not involve browser download
- All other PHP files, Ansible roles, group_vars, Docker images, schema — unchanged

---

## Progress

### Completed

_(none — pending implementation approval)_

### Remaining — This Feature

- Phase 1, Step 1: Section E description compatibility notice
- Phase 1, Step 2: Azure radio label compatibility annotation
- Phase 2, Step 3: Streaming download block with Blob fallback
- Phase 3, Step 4: Playwright compatibility notice test
- Phase 3, Step 5: Playwright `showSaveFilePicker` mock for regression test

### Remaining — Follow-on Tasks

_(none — self-contained JS and Playwright change; no schema, PHP, Ansible, or iOS scope)_
