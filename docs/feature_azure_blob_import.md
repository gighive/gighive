# Feature: Azure Blob Storage Import (Section F)

**Status**: Draft  
**Date**: 2026-07-26  
**Related docs**: `docs/feature_azure_blob_export.md`, `docs/refactored_admin_export_media.md`

---

## Overview

Extends Section F (Import Media Archive) of `admin/admin_system.php` with a second source
option: **Azure Blob Storage**. When Azure credentials are configured, the admin selects a radio
button, pastes the blob prefix from a prior export, and the PHP worker downloads each blob
directly from Azure into the bind-mounted media volumes — no local archive file is required.

The local archive upload path (existing behavior) is unchanged.

---

## Primary Use Case and Scope

Self-hosted GigHive operators who exported their media library to Azure Blob Storage via the
Section E export feature and now want to restore it — or seed a new instance — from those blobs:

- The current local import requires uploading a potentially multi-gigabyte tar.gz archive through
  the browser, which is impractical over slow connections and subject to PHP upload limits.
- The Azure import path bypasses the browser entirely: the PHP worker streams blobs from
  Azure directly into `/var/www/html/audio` and `/var/www/html/video` using `curl GET`.
- The blob prefix displayed in the Section E success banner (e.g.
  `gighive-export/20260725-093012/all/`) is pasted directly into the import form — no manual
  blob navigation required.

**Scope**: Companion restore path for `feature_azure_blob_export.md`. Covers all file types
exported by that feature: audio files, video files, and video thumbnails.

**Out of scope for this doc**:
- Browsing or listing available export prefixes in the UI (admin pastes the known prefix)
- Partial re-import (re-running import on an existing prefix is already safe — files present
  on disk are skipped idempotently, matching the local import behavior)
- S3 or Backblaze B2 support (extend via `BLOB_PROVIDER` env var when needed)

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| No new env vars | Reuse `AZURE_BLOB_ACCOUNT_NAME`, `AZURE_BLOB_CONTAINER`, `AZURE_BLOB_SAS_TOKEN` from export feature | Import and export operate on the same container; a single credential set is simpler to manage |
| `$__azure_available` flag | Reuse the flag already computed before Section E in `admin_system.php` | Both sections live in the same PHP file; no re-computation needed |
| Source toggle conditional render | Same pattern as export Destination row — only render when all three `AZURE_BLOB_*` env vars are non-empty | Consistent UX: admins without Azure credentials see an unchanged Section F |
| Blob listing mechanism | Azure REST API `GET ?restype=container&comp=list&prefix=…` with XML response, `simplexml_load_string()` | No SDK; same curl-only pattern as export worker; handles pagination via `NextMarker` loop |
| `bloblist.json` temp file | `mode=prepare` lists blobs, stores `[{blob_name, size}, …]` in `/tmp/gighive_azure_import_prepare_{token}.json`; `mode=start` moves it to job dir | Avoids double-listing on large libraries; mirrors the export's `filelist.json` pattern exactly; `prepare_token` flow unchanged |
| Separate worker file | `import_media_zip_worker_azure.php` (new) | Keeps local-archive and Azure workers focused and independently testable; existing `import_media_zip_worker.php` is **unchanged** |
| Shared status polling | Both workers write the same `status.json` step model; `import_media_zip_status.php` is **unchanged** | Frontend `pollJobStatus()` requires no modification |
| UI step structure | Azure path: 2 steps — `List blobs` + `Import files`; local path: 3 steps unchanged | Listing is synchronous and fast relative to downloading; collapsing it into a single "List blobs" prepare step is cleaner than showing Upload + Inspect (which don't apply to Azure) |
| Blob name parsing | Strip prefix from blob name, then classify by path segment: `audio/`, `video/`, `video/thumbnails/` | Mirrors the export blob naming convention; uses existing `isValidMediaEntry()` / `isValidThumbnailEntry()` helpers from `admin_media_lib.php` |
| Disk space check | Worker checks `disk_free_space('/var/www/html') >= totalBytes * 1.1` after listing blobs | Mirrors the tar.gz branch of the existing local worker; fast-fails before any file is written |
| Atomic write | Worker downloads each blob to `{dest}.tmp` first, then `rename()` on success | Prevents partial files on disk if the worker is killed mid-download |
| SAS token permissions | `Read + List` required for import; `Write + Create` required for export; recommend generating a single SAS with all four | Operators with an export-only SAS must regenerate it; the SAS requirements section in the export doc should be updated accordingly |
| Upload error handling | Per-blob HTTP status check; worker writes `state: error` on first non-200 GET response with the blob name and HTTP code in `error_message` | Fast-fail on auth or quota errors; mirrors export worker behavior |

---

## UX Rationale: Conditional Render vs. Always-Show-Disabled

Identical rationale to the export feature. GigHive's admin audience is technically sophisticated;
the Source row appears automatically when credentials are present. Admins without Azure credentials
see Section F exactly as it exists today.

---

## UX Wireframe

All states are for **Section F: Import Media Archive** in `admin_system.php`.

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE A — No Azure credentials (unchanged from today)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Section F: Import Media Archive
  ─────────────────────────────────────────────────────────────────
  Import audio and video files from a GigHive export archive…
  [Source row not rendered — Section F looks identical to today]

  Archive file  [file picker]

  [ Import Archive ]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE B — Azure credentials present, local selected (default)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Source                                                  ← NEW row
  ◉ Upload from computer
  ○ Import from Azure Blob Storage

  Archive file  [file picker]

  [ Import Archive ]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE C — Azure selected (at rest)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Source
  ○ Upload from computer
  ◉ Import from Azure Blob Storage

  Blob prefix   ┌──────────────────────────────────────────────────┐
                │ e.g. gighive-export/20260725-093012/all/         │
                └──────────────────────────────────────────────────┘

  [ Import Archive ]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE D — Import in progress (Azure selected)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Source
  ○ Upload from computer    [radios disabled during import]
  ◉ Import from Azure Blob Storage

  Blob prefix   [gighive-export/20260725-093012/all/]  [disabled]

  ┌──────────────────────────────────────────────────────────────┐
  │  ✓ List blobs       312 blobs found · 4.2 GB                 │
  │  ↻ Import files     142 / 312 files imported…                │
  └──────────────────────────────────────────────────────────────┘

  [ Importing from Azure… ]  [disabled]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE E — Import complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ┌──────────────────────────────────────────────────────────────┐
  │  ✓ List blobs       312 blobs found · 4.2 GB                 │
  │  ✓ Import files     299 added, 13 already on disk, 0 skipped │
  │                     (4.1 GB added) (3m 42s)                  │
  └──────────────────────────────────────────────────────────────┘

  [ Import Archive ]  [re-enabled]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE F — Error (e.g. expired or missing Read permission on SAS)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ┌──────────────────────────────────────────────────────────────┐
  │  ✗ List blobs       HTTP 403 listing blobs at prefix.        │
  │                     Check SAS token has Read + List perms.   │
  └──────────────────────────────────────────────────────────────┘

  [ Import Archive ]  [re-enabled; admin can retry or switch to local]
```

---

## Azure SAS Token Requirements

The same SAS token (`AZURE_BLOB_SAS_TOKEN`) is used for both export and import. Operators
who generated a SAS token for export with only `Write + Create` permissions must **regenerate**
it to add `Read + List` before using the import feature.

| Setting | Required value |
|---|---|
| Allowed services | Blob |
| Allowed resource types | Container (for list), Object (for get/put) |
| Allowed permissions | **Read, List, Write, Create** (all four for combined import + export) |
| Allowed protocols | HTTPS only |
| Expiry | Set to a reasonable horizon (e.g. 1 year for a backup credential) |

Update `secrets.example.yml` comment to reflect the expanded permissions requirement.

---

## Ansible / Infrastructure Changes

### No new env vars

`AZURE_BLOB_ACCOUNT_NAME`, `AZURE_BLOB_CONTAINER`, and `AZURE_BLOB_SAS_TOKEN` are already
present in `.env.j2` and all `secrets.yml` files from the export feature. No new Ansible
variables or template lines are needed.

### `secrets.example.yml` — update the SAS token comment only

Change the comment on `azure_blob_sas_token` to note the expanded permission requirement:

```yaml
# Azure Blob Storage export + import (Section E and F). Leave blank to disable the Azure options.
# Obtain a SAS token from Azure portal → Storage account → Shared access signature.
# Required SAS permissions: Blob service, Container + Object resource types,
#   Write + Create (for export) + Read + List (for import).
# For combined import + export: enable all four permissions on one token.
azure_blob_account_name: ""
azure_blob_container: ""
azure_blob_sas_token: ""
```

### `post_build_checks/tasks/main.yml` — one new `[smoke]` task

Add an auth-check task immediately after the existing `export_media_worker_azure.php` check:

```yaml
- name: /admin/import_media_zip_worker_azure.php should require auth (401)
  uri:
    url: "{{ gighive_base_url }}/admin/import_media_zip_worker_azure.php"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    return_content: no
    status_code: 401
    headers: "{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit }}"
  changed_when: false
  tags: [smoke]
```

---

## Files Under Change

### New
1. `ansible/roles/docker/files/apache/webroot/admin/import_media_zip_worker_azure.php` — CLI
   worker; reads `bloblist.json` from job dir; downloads each blob from Azure via `curl GET`;
   writes files atomically to bind-mounted volumes; updates `status.json` after every file

### Modified
1. `ansible/roles/docker/files/apache/webroot/admin/import_media_zip.php` — Accept `source`
   POST param (`local`|`azure`); on `prepare` for azure: validate prefix, list blobs, store
   `bloblist.json` temp file, return counts; on `start` for azure: move bloblist to job dir,
   spawn `import_media_zip_worker_azure.php`
2. `ansible/roles/docker/files/apache/webroot/admin/admin_system.php` — Section F: reuse
   `$__azure_available` flag to conditionally render Source row; toggle `#import-local-row`
   and `#import-azure-row` visibility on radio change; JS updates in `doImportMediaZip()`
3. `ansible/roles/post_build_checks/tasks/main.yml` — One new `[smoke]` auth-check task for
   `import_media_zip_worker_azure.php`
4. `ansible/inventories/group_vars/gighive/secrets.example.yml` — Updated SAS comment only

**Unchanged**: `import_media_zip_status.php`, `import_media_zip_worker.php`,
`export_media_worker_azure.php`, `export_media.php`, `admin_media_lib.php`,
`.env.j2`, `secrets.yml` files (values unchanged; comment update only in example file).

---

## Implementation Plan

### Phase 1 — PHP Backend

**Step 1 — `import_media_zip_worker_azure.php` (new)**

CLI-only (`PHP_SAPI !== 'cli'` → exit 1). Accepts `--job_id=`.

Must `require_once __DIR__ . '/admin_media_lib.php'` to reuse `writeJobStatus()`,
`isValidMediaEntry()`, and `isValidThumbnailEntry()`.

Key structure:

```
1.  Parse --job_id; validate format /^[a-f0-9]{16}$/
2.  Resolve $jobDir, $bloblistPath ($jobDir . 'bloblist.json'), $jsonPath from sys_get_temp_dir()
3.  Read Azure credentials from getenv(); fail with writeJobStatus error state if any are empty
4.  Read $rows from bloblist.json (array of {blob_name, size}); unlink it
5.  Read $prefix from $jobDir . 'prefix.txt'; if file is missing or empty →
    writeJobStatus state=error 'prefix.txt missing from job directory'; exit(1)
    Trim trailing slash then re-add it (normalize)
6.  Validate destination dirs: /var/www/html/audio and /var/www/html/video must exist
7.  Disk space check: sum $row['size'] across all rows; check disk_free_space('/var/www/html') >= totalBytes * 1.1
    On failure: writeJobStatus state=error 'Insufficient disk space on destination volume'
8.  set_time_limit(0)
9.  Write initial running status: steps=[{name:'List blobs', status:'ok', message: count . ' blobs found · ' . fmtBytes}, {name:'Import files', status:'running', message:'Starting…'}]
10. For each $row in $rows:
    a. $blobName = $row['blob_name']
       $size     = (int)$row['size']
       Strip $prefix from $blobName → $relative (e.g. 'audio/abc123.mp3')
    b. Classify $relative:
       - starts with 'audio/'        → type='audio',     $dest='/var/www/html/audio/' . basename($relative)
       - starts with 'video/thumbnails/' → type='thumbnail', $dest='/var/www/html/video/thumbnails/' . basename($relative)
       - starts with 'video/'        → type='video',     $dest='/var/www/html/video/' . basename($relative)
       - else → $skipped++; continue
    c. Validate filename:
       - For audio/video: isValidMediaEntry(basename($relative), $audioExtsSet, $videoExtsSet)
       - For thumbnail:   isValidThumbnailEntry('thumbnails/' . basename($relative))
       - Invalid → $skipped++; continue
    d. If is_file($dest): $alreadyExists++; $processed++; write status; continue
    e. Ensure dest dir exists (@mkdir for thumbnails subdir)
    f. $tmpDest = $dest . '.tmp'
       downloadBlobFromAzure($blobName, $tmpDest, $azAccount, $azContainer, $azSas)
       rename($tmpDest, $dest)
       *** NEVER log or echo the GET URL — it contains the SAS token ***
    g. $added++; $bytesAdded += $size; $processed++
    h. writeJobStatus after every file
11. On completion: writeJobStatus state=done with added, alreadyExists, skipped, bytesAdded, completed_at
12. catch Throwable: writeJobStatus state=error; @unlink($tmpDest ?? '') on cleanup
```

Helper function in worker file (local, not in `admin_media_lib.php`):

```
downloadBlobFromAzure(string $blobName, string $localTmpPath, string $account, string $container, string $sas): void
  - Build GET URL: rawurlencode each path segment, append '?' . $sas
  - *** NEVER log $url ***
  - Open $fh = fopen($localTmpPath, 'wb')
  - curl_setopt_array: CURLOPT_URL, CURLOPT_FILE=>$fh, CURLOPT_FOLLOWLOCATION=>false,
      CURLOPT_TIMEOUT=>0, CURLOPT_HTTPHEADER=>['x-ms-version: 2020-04-08']
  - try/finally: always fclose($fh); curl_close($ch)
  - Check HTTP code: 200 = success; else throw RuntimeException with basename($blobName) + HTTP code
    (never the URL)
  - On exception: @unlink($localTmpPath) before re-throwing
```

**Step 2 — `import_media_zip.php` modifications**

- Add `$source = in_array(trim($_POST['source'] ?? ''), ['local', 'azure'], true) ? trim($_POST['source']) : 'local';`
- **In `prepare` mode, when `$source === 'azure'`**:
  - Skip the `$_FILES['zip_file']` check entirely
  - Read `$prefix = trim($_POST['prefix'] ?? '')`
  - Validate prefix: non-empty, no `..`, no leading `/`, no null bytes
  - Normalize: ensure trailing slash
  - Validate Azure env vars present; return HTTP 400 if any missing
  - `set_time_limit(0)` — listing a large library may take several seconds
  - Call `listAzureBlobs($azAccount, $azContainer, $azSas, $prefix)` — returns
    `[['blob_name' => ..., 'size' => ...], ...]` with pagination (see below)
  - Classify each blob name: audio / video / thumbnail / unsupported
  - Validate each media/thumbnail filename with `isValidMediaEntry()` / `isValidThumbnailEntry()`
  - Compute `$audioCount`, `$videoCount`, `$unsupportedCount`, `$totalBytes`
  - Guard: if `$audioCount + $videoCount === 0` → return HTTP 400
    `{success: false, error: 'No importable blobs found under prefix — check prefix or SAS Read+List permissions'}`
    (do not store a temp file for a zero-result listing)
  - Store blob list: `file_put_contents('/tmp/gighive_azure_import_prepare_' . $token . '.json', json_encode($rows))`
  - Return `{success, prepare_token, audio_count, video_count, unsupported_count, total_bytes}`

- **In `start` mode, when `$source === 'azure'`**:
  - Validate `$prepareToken` as before
  - Look up temp file: `/tmp/gighive_azure_import_prepare_{token}.json`
    (different path pattern from local's `gighive_zip_prepare_` — no collision)
  - Check file exists and is < 1800s old
  - Read `$prefix` from `$_POST['prefix']` — re-validate (same rules as prepare)
  - Create job dir; write `source.txt` = 'azure', `prefix.txt` = $prefix
  - Copy bloblist json to `$jobDir . 'bloblist.json'`; unlink temp file
  - Write initial `status.json` with steps:
    ```php
    [
        ['name' => 'List blobs',   'status' => 'ok',      'message' => count($rows) . ' blobs found',
         'progress' => null],
        ['name' => 'Import files', 'status' => 'running',  'message' => 'Starting\u2026',
         'progress' => ['processed' => 0, 'total' => count($rows)]],
    ]
    ```
  - `exec('php ' . escapeshellarg(__DIR__ . '/import_media_zip_worker_azure.php') . ' --job_id=' . escapeshellarg($jobId) . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &')`
  - Return `{success: true, job_id: ...}`

`listAzureBlobs()` — private helper function at bottom of `import_media_zip.php`
(only called from this file; not a candidate for `admin_media_lib.php`):

```
listAzureBlobs(string $account, string $container, string $sas, string $prefix): array
  Loops with NextMarker pagination:
    GET https://{account}.blob.core.windows.net/{container}
        ?restype=container&comp=list
        &prefix={rawurlencode($prefix)}
        [&marker={rawurlencode($marker)}]
        &{$sas}
    Headers: x-ms-version: 2020-04-08
    Timeout: 30s per page
    On non-200: throw RuntimeException with HTTP code (never the URL)
    Parse XML: $xml = simplexml_load_string($body);
    if ($xml === false) throw RuntimeException('Invalid XML from Azure list response: HTTP ' . $code)
    Append each Blob: ['blob_name' => (string)$blob->Name, 'size' => (int)$blob->Properties->{'Content-Length'}]
    $marker = (string)NextMarker — loop while non-empty
  Returns flat array of all blobs across all pages
  Hard cap: 500,000 blobs (safety guard against runaway listing)
  *** NEVER log or echo the GET URL — it contains the SAS token ***
  Exception messages must contain only the HTTP status code — never the URL
```

---

### Phase 2 — Admin UI (`admin_system.php`)

**Step 3 — Section F HTML changes**

The `$__azure_available` flag is already computed at the top of Section E and is available
throughout the rest of the page — no re-computation needed.

Wrap a Source row and a new Blob prefix row in PHP conditionals. Add `id` attributes to
the existing Archive file row and new prefix row for JS toggling:

```html
<?php if ($__azure_available): ?>
<div class="row">
  <label>Source</label>
  <div>
    <label style="margin-right:1.5em">
      <input type="radio" name="import_source" id="import_src_local" value="local" checked
             onchange="onImportSourceChange()" />
      Upload from computer
    </label>
    <label>
      <input type="radio" name="import_source" id="import_src_azure" value="azure"
             onchange="onImportSourceChange()" />
      Import from Azure Blob Storage
    </label>
  </div>
</div>
<?php endif; ?>
<div class="row" id="import-local-row">
  <label for="import_zip_file">Archive file</label>
  <input type="file" id="import_zip_file" name="zip_file" accept=".tar.gz,.tgz,.gz" />
</div>
<?php if ($__azure_available): ?>
<div class="row" id="import-azure-row" style="display:none">
  <label for="import_blob_prefix">Blob prefix</label>
  <input type="text" id="import_blob_prefix" placeholder="e.g. gighive-export/20260725-093012/all/" />
</div>
<?php endif; ?>
```

**Step 4 — `onImportSourceChange()` JS function**

Add alongside the existing `onExportDestChange()` function:

```js
function onImportSourceChange() {
  const src = (document.querySelector('input[name="import_source"]:checked') || {}).value || 'local';
  const localRow  = document.getElementById('import-local-row');
  const azureRow  = document.getElementById('import-azure-row');
  if (localRow)  localRow.style.display  = src === 'local' ? '' : 'none';
  if (azureRow)  azureRow.style.display  = src === 'azure' ? '' : 'none';
  document.getElementById('importZipBtn').textContent = src === 'azure' ? 'Import from Azure' : 'Import Archive';
}
```

**Step 5 — `doImportMediaZip()` JS changes**

Read the selected source at the top:

```js
const src = (document.querySelector('input[name="import_source"]:checked') || {}).value || 'local';
```

**When `src === 'local'`**: existing flow is completely unchanged.

**When `src === 'azure'`**:

- Guard: check `#import_blob_prefix` value is non-empty; show error if blank
- Disable radios and prefix input during import
- Steps (2-element array, not 3):
  ```js
  const steps = [
    { name: 'List blobs',   status: 'running', message: 'Listing\u2026', progress: null },
    { name: 'Import files', status: 'pending', message: '', progress: null },
  ];
  ```
- **Prepare**: use `fetch()` (not XHR — no file upload):
  ```js
  const prepResp = await fetch('import_media_zip.php', {
    method: 'POST',
    body: new URLSearchParams({ mode: 'prepare', source: 'azure', prefix: blobPrefix }),
    cache: 'no-store',
  });
  ```
  On success: update `steps[0]` to `ok` with `X audio + Y video blobs found (Z)`;
  display `steps[1]` as pending
- **Confirm dialog**:
  ```
  "X audio + Y video files ready to import (Z) from Azure Blob Storage.
  Files already on disk are skipped safely.
  Do you wish to import?"
  ```
  Omit free-space reminder — disk space is checked by the worker, not the admin.
- **Start**:
  ```js
  const startResp = await fetch('import_media_zip.php', {
    method: 'POST',
    body: new URLSearchParams({ mode: 'start', source: 'azure',
                                prepare_token: prepareToken, prefix: blobPrefix }),
    cache: 'no-store',
  });
  ```
- **Poll**: same `pollJobStatus()` call to `import_media_zip_status.php`, interval 1500ms.
  Poll callback maps both worker steps to the UI steps array:
  ```js
  if (data && Array.isArray(data.steps)) {
    data.steps.forEach(function (s, i) { if (i < steps.length) steps[i] = s; });
  }
  render();
  ```
- **Done state**: full-width green banner showing added count, bytes, and elapsed time
  (same pattern as export Azure done banner):
  ```
  Import complete — 299 file(s) restored from Azure Blob Storage (3m 42s)
  ```
- **Button label during import**: `'Importing from Azure\u2026'`
- **Re-enable**: in `finally` block restore button label and re-enable radios + prefix input

---

### Phase 3 — Ansible Smoke Test

**Step 6 — `post_build_checks/tasks/main.yml`**

Add the single auth-check task shown in the Ansible / Infrastructure Changes section above,
immediately after the existing `export_media_worker_azure.php` auth check.

---

### SonarQube / Best-Practice Notes

| Rule | Location | Finding |
|---|---|---|
| RSPEC-3776 (cognitive complexity) | `import_media_zip_worker_azure.php` — main loop | Per-blob classification, path derivation, dedup check, download, and status write all nested in `foreach`. Extract a `classifyBlob(string $blobName, string $prefix, array $audioExtsSet, array $videoExtsSet): array` helper to flatten the loop body. |
| RSPEC-2635 (sensitive data in logs) | `import_media_zip_worker_azure.php`, `import_media_zip.php` | GET URL and LIST URL contain the SAS token. Neither file may `echo`, `fwrite(STDERR, ...)`, or log any URL touching Azure. Error messages use blob name + HTTP code only. |
| Brittle: 3-way credential check | `import_media_zip.php`, `import_media_zip_worker_azure.php` | Both independently call `getenv()` on the same 3 vars. Acceptable for v1; same candidate for a shared helper in `admin_media_lib.php` noted in the export doc. |
| Brittle: duplicated blob classification | `import_media_zip.php` (prepare, for counting) and `import_media_zip_worker_azure.php` (main loop, for routing) | The `audio/` / `video/` / `video/thumbnails/` classification logic runs in both files. Each file should extract a local private `classifyBlobRelativePath(string $relative): ?string` helper to avoid duplicated inline branching. |
| Brittle: duplicated prefix validation | `import_media_zip.php` `prepare` and `start` modes | Both modes validate the prefix with the same rules (non-empty, no `..`, no leading `/`, no null bytes). Extract a private `validateAzurePrefix(string $prefix): void` helper (throws on invalid) used in both branches. |
| Prefix injection | `import_media_zip.php` | The prefix is user-supplied and included in Azure API calls. Validate strictly before use: non-empty, no `..`, no leading `/`, no null bytes. The value is `rawurlencode()`d per segment before being placed in any URL — never interpolated raw. |

---

### Phase 4 — Verification

**Step 7 — Manual verification on dev**

Prerequisites: Azure credentials in `gighive2/secrets.yml` with a SAS token that has
`Read + List` permissions (or all four for combined use); a prior Section E export must have
completed and the blob prefix noted from the success banner.

Verification sequence:
1. Navigate to `https://devvm.gighive.internal/admin/admin_system.php` → Section F.
2. Confirm the **Source row is visible** (Azure credentials trigger conditional render).
3. Confirm local radio is the default; file picker is visible and prefix input is hidden.
4. Click the Azure radio — confirm file picker hides, prefix input appears.
5. Paste a valid blob prefix from a prior export; click **Import from Azure**.
6. Confirm prepare step shows blob count and size; confirm dialog; watch Import files progress.
7. On completion, confirm the green banner shows added/already-on-disk counts and elapsed time.
8. Verify files landed on disk:
   ```bash
   ssh ubuntu@devvm.gighive.internal \
     "docker exec apacheWebServer ls /var/www/html/audio | head -5"
   ```
9. Verify idempotency: run the same import again; confirm all files show as "already on disk"
   and the worker completes cleanly.
10. Verify local import still works: switch radio to "Upload from computer" and import a small
    tar.gz archive; confirm file is uploaded and imported as before.

**Failure modes to test**:
- Expired or invalid SAS token → `List blobs` step shows HTTP 403 error; button re-enables
- Prefix with no matching blobs → worker completes with 0 added, 0 already on disk →
  `state=error` ("No blobs found under prefix") — **no silent success**
- Prefix missing trailing slash → PHP normalizes by appending `/` before listing
- SAS has Write+Create but not Read+List → HTTP 403 on list call; clear error message in UI

---

## Rollback

All changes are additive. To roll back:
- Remove the Source radio group HTML from Section F in `admin_system.php` (restores single-input behavior; the file picker row has no conditional wrapping so it remains visible)
- Remove the `source` param handling from `import_media_zip.php` (ignored if absent — no effect on existing local callers)
- Remove `import_media_zip_worker_azure.php` (never called if `source` param is absent)
- Remove the auth-check smoke task for `import_media_zip_worker_azure.php` from `post_build_checks/tasks/main.yml`
- Revert the `secrets.example.yml` comment if desired

No database schema changes, no file moves, no destructive side effects. The three `AZURE_BLOB_*` env vars remain in `.env.j2` and `secrets.yml` (they are also used by Section E export — do not remove them).

---

## Playwright Regression Note

The Playwright test (`admin-pages.spec.ts`) exercises Section F via `#importZipBtn` and
`#importZipStatus` selectors. With Azure credentials absent in the dev test environment
(default), the Source row is not rendered and the test flow is unchanged — no test update
is required.

If credentials are ever added to the test environment, the test would need a step to assert
the Source row is visible and the local radio is the default.

---

## Assumptions

- PHP `curl` extension and `simplexml_load_string()` are available in `apacheWebServer`
  (both confirmed present; SimpleXML is bundled with PHP 8.x by default).
- All individual blob files are within reasonable single-GET size limits (no streaming
  resume support in v1; a failed GET is caught as a non-200 response and the worker
  fails cleanly on the first affected blob).
- The SAS token has `Read + List` permissions. If only `Write + Create` were granted for
  export, the operator must regenerate the token before using import.
- The admin is responsible for SAS token expiry management; no expiry warning is shown in the UI.
- Blob names in Azure match the naming convention produced by `export_media_worker_azure.php`
  (i.e. `{prefix}/audio/{sha256}.ext`, `{prefix}/video/{sha256}.ext`,
  `{prefix}/video/thumbnails/{sha256}.png`). Blobs with names that do not match
  `isValidMediaEntry()` or `isValidThumbnailEntry()` are silently skipped.

---

## Future Considerations

- **Browse available prefixes**: add a "Browse exports" button that calls the Azure List API
  with the `gighive-export/` root prefix and presents available batch timestamps in a dropdown.
- **Multi-provider support**: extend with `BLOB_PROVIDER=azure|s3|b2` env var (same roadmap
  as export feature).
- **SaaS alignment**: when tenant-scoped file paths land, blob prefix structure gains a
  `tenant_id` component matching the export convention.
