# Feature: Azure Blob Storage Export (Section E)

**Status**: Planning  
**Date**: 2026-07-25  
**Related docs**: `docs/feature_saas_model_changes.md` (Steps 17, 19), `docs/refactored_admin_export_media.md`

---

## Overview

Extends Section E (Export Media Archive) of `admin/admin_system.php` with a second destination
option: **Azure Blob Storage**. When Azure credentials are configured, the admin selects a radio
button and the PHP worker streams each media file from the bind-mounted volumes directly into
Azure as individual blobs — no local `tar.gz` is built, no temp disk space is consumed.

The local tar download path (existing behavior) is unchanged.

---

## Primary Use Case and Scope

Self-hosted GigHive operators who want off-site backup of their media library:

- The current local export requires enough free space on the Docker container's `/tmp` filesystem
  to hold the entire compressed archive. With large media libraries this is often impractical.
- Media files are bind-mounted into `apacheWebServer` at `/var/www/html/audio` and
  `/var/www/html/video` — the PHP worker can read directly from there and stream to Azure
  without touching the container's own disk beyond a few KB of job state files.

**Scope**: Self-hosted backup only. This is a stepping stone toward the SaaS object-storage
layer (Step 19) and per-tenant data export (Step 17) described in `feature_saas_model_changes.md`,
but imposes no schema changes and does not require `SAAS_MODE`.

**Out of scope for this doc**:
- Restore from Azure (separate feature)
- S3 or Backblaze B2 support (extend via `BLOB_PROVIDER` env var when needed)
- Multipart block upload for files > 5 GiB (Azure single-PUT limit; deferred — no practical
  media file approaches this)

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Auth mechanism | SAS token in `.env`; server-side only | No browser OAuth; PHP worker POSTs directly to Azure REST API; SAS is revocable, least-privilege, and follows `openai_api_key` pattern |
| No local tar for Azure path | Stream each file individually via `curl PUT` | Eliminates the 1:1 temp disk requirement; bind-mount files are directly readable by the worker |
| Azure upload mechanism | PHP `curl` with `CURLOPT_INFILE` streaming | curl 8.5.0 confirmed in `apacheWebServer`; no SDK or Composer dependency; memory-efficient per-file streaming |
| Blob naming | Full path: `gighive-export/{YYYYMMDD-HHmmss}/{org}/{audio\|video}/{sha256}.ext` and `gighive-export/{YYYYMMDD-HHmmss}/{org}/video/thumbnails/{sha256}.png` where `{org}` is the sanitized org filter or `all` when no filter is set | Timestamped prefix separates backup batches; org segment mirrors existing filename label convention; directory structure mirrors on-disk layout for straightforward future restore |
| Separate worker file | `export_media_worker_azure.php` (new) | Keeps tar and Azure workers focused and independently testable; existing `export_media_worker.php` is **unchanged** |
| Shared job mechanism | Both workers use the same `filelist.json` / `status.json` / `status` step model | Frontend `pollJobStatus()` works without modification; `export_media_status.php` is unchanged |
| No `export_media_download.php` for Azure | Done state emits blob container + prefix as confirmation; no file to download | No local archive exists to serve; future restore feature will list blobs by prefix |
| Azure option availability | PHP conditionally renders the entire Destination row only when all three `AZURE_BLOB_*` env vars are non-empty; if absent, Section E is visually unchanged from today | Cleaner for the common case (no Azure credentials); avoids a permanently-greyed option that acts as inline documentation rather than a UI control; see *UX Rationale* section |
| Upload error handling | Per-file HTTP status check; worker writes `state: error` on first non-201 response with the blob name and HTTP code in `error_message` | Fast-fail on auth or quota errors; no partial silent upload |
| SAS token appended verbatim | `$sasToken` from `getenv()` is appended as-is after `?` in the PUT URL | Azure portal outputs a ready-to-use query string; re-encoding would break signature validation |

---

## UX Rationale: Conditional Render vs. Always-Show-Disabled

Two options were considered for surfacing the Azure Blob Storage destination:

**Option A — Always show, disabled when credentials absent**  
Render the radio at all times, but mark it `disabled` with an inline `.env` instruction when credentials are not configured.

**Option B — Conditional render (chosen)**  
Render the Destination row only when all three `AZURE_BLOB_*` env vars are non-empty. When credentials are absent, Section E is visually identical to today.

**Why Option B:**

The greyed/disabled pattern is appropriate for *locked premium features* — it communicates "this exists, upgrade to unlock." For an infrastructure credential the message is different: there is nothing to configure in the UI until the admin has already decided they want Azure backup and has set up the credentials in `.env`. An admin without Azure credentials gains nothing from seeing a permanently-disabled radio with a `.env` instruction inline — that is documentation, not a UI control, and it adds visual noise to every Section E view on every install.

GigHive's admin audience is technically sophisticated (self-hosted operators); discoverability via UI is a lower priority than a clean, uncluttered interface. Admins who want this feature will configure credentials first; the Destination row then appears automatically on the next page load.

The implementation is also simpler: a single `<?php if ($__azure_available): ?>` block around the Destination row replaces the conditional `disabled`/muted/span markup.

---

## UX Wireframe

All states are for **Section E: Export Media Archive** in `admin_system.php`.
The filter rows and explanatory text are unchanged; only the Destination row and button are new or modified.

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE A — No Azure credentials (unchanged from today)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Section E: Export Media Archive
  ─────────────────────────────────────────────────────────────────
  Download a tar.gz archive of media files currently on disk...
  [explanatory text — unchanged]

  Band / Event filter  (leave blank to export all media)
  ┌──────────────────────────────────────────────────────┐
  │ e.g. tutorial                                        │
  └──────────────────────────────────────────────────────┘

  File type
  ┌────────────────────────────┐
  │ All (audio + video)     ▾  │
  └────────────────────────────┘

  [Destination row not rendered — Section E looks identical to today]

  [ Download Archive ]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE B — Azure credentials present, local selected (default)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Band / Event filter ...
  File type ...

  Destination                                              ← NEW row (rendered only when configured)
  ◉ Download to browser (tar.gz)
  ○ Send to Azure Blob Storage

  [ Download Archive ]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE C — Azure credentials present, Azure selected (at rest)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Destination
  ○ Download to browser (tar.gz)
  ◉ Send to Azure Blob Storage

  [ Download Archive ]                                     ← label unchanged at rest;
                                                             changes to "Uploading to Azure…"
                                                             once upload begins (see State D)


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE D — Upload in progress (Azure selected)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Destination
  ○ Download to browser (tar.gz)   [radios disabled during upload]
  ◉ Send to Azure Blob Storage

  ┌──────────────────────────────────────────────────────────────┐
  │  ✓ Query database      312 files · 4.2 GB found              │
  │  ↻ Upload to Azure     142 / 312 blobs uploaded…             │
  └──────────────────────────────────────────────────────────────┘

  [ Uploading to Azure… ]  [disabled]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE E — Upload complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ┌──────────────────────────────────────────────────────────────┐
  │  ✓ Query database      312 files · 4.2 GB found              │
  │  ✓ Upload to Azure     Uploaded to Azure:                    │
  │                        gighive-export/20260725-093012/all/   │
  │                        312 blobs uploaded · 0 skipped        │
  └──────────────────────────────────────────────────────────────┘

  [ Download Archive ]     [re-enabled]


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  STATE F — Upload error (e.g. expired SAS token)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ┌──────────────────────────────────────────────────────────────┐
  │  ✓ Query database      312 files · 4.2 GB found              │
  │  ✗ Upload to Azure     HTTP 403 uploading                    │
  │                        gighive-export/.../audio/abc123.mp3   │
  │                        Check SAS token permissions/expiry.   │
  └──────────────────────────────────────────────────────────────┘

  [ Download Archive ]     [re-enabled; admin can retry or switch to local]
```

---

## Azure SAS Token Requirements

When generating a SAS token in the Azure portal (Storage account → Shared access signature):

| Setting | Required value |
|---|---|
| Allowed services | Blob |
| Allowed resource types | Container, Object |
| Allowed permissions | Write, Create (minimum); add List for future restore |
| Allowed protocols | HTTPS only |
| Expiry | Set to a reasonable horizon (e.g. 1 year for a backup credential) |

The generated token string (`sv=...&ss=b&...&sig=...`) is stored verbatim in `azure_blob_sas_token`.

---

## Ansible / Infrastructure Changes

### `secrets.yml` — all three environments (gighive, gighive2, prod) + `secrets.example.yml`

```yaml
# Azure Blob Storage export (Section E). Leave blank to disable the Azure option.
# Obtain a SAS token from Azure portal → Storage account → Shared access signature.
# Required SAS permissions: Blob service, Container + Object resource types, Write + Create.
azure_blob_account_name: ""
azure_blob_container: ""
azure_blob_sas_token: ""
```

**Important**: when populating a real SAS token, the value contains `&`, `=`, and `+` characters.
YAML will misparse an unquoted value that starts or contains these. Always use a single-quoted string:
```yaml
azure_blob_sas_token: 'sv=2023-01-03&ss=b&srt=o&sp=wc&se=2027-01-01&spr=https&sig=XXXX'
```

`secrets.example.yml` gets the same block with a comment linking to the Azure portal.

### `.env.j2` — three new lines appended

```
AZURE_BLOB_ACCOUNT_NAME={{ azure_blob_account_name | default('') }}
AZURE_BLOB_CONTAINER={{ azure_blob_container | default('') }}
AZURE_BLOB_SAS_TOKEN={{ azure_blob_sas_token | default('') }}
```

No `group_vars` feature flags needed — presence of non-empty env vars is the availability signal.

---

## Files Under Change

### New
1. `ansible/roles/docker/files/apache/webroot/admin/export_media_worker_azure.php` — CLI worker; iterates `filelist.json`; streams each file to Azure via `curl PUT` against the Blob REST API; writes per-file progress to `status.json`; no local archive created

### Modified
1. `ansible/roles/docker/files/apache/webroot/admin/export_media.php` — Accept `destination` POST param (`local`|`azure`); on `prepare`: validate Azure env vars present when `azure`; on `start`: dispatch to `export_media_worker_azure.php` when `azure`
2. `ansible/roles/docker/files/apache/webroot/admin/admin_system.php` — Section E: PHP `$__azure_available` flag conditionally renders Destination row; JS reads selected `destination` and passes it in all fetch calls; Azure done state renders blob prefix instead of triggering a file download
3. `ansible/roles/docker/templates/.env.j2` — Three new Azure env var lines (bottom of file)
4. `ansible/inventories/group_vars/gighive/secrets.yml` — Three new Azure vars (empty string defaults)
5. `ansible/inventories/group_vars/gighive2/secrets.yml` — Same
6. `ansible/inventories/group_vars/prod/secrets.yml` — Same
7. `ansible/inventories/group_vars/gighive/secrets.example.yml` — Same with explanatory comment
8. `ansible/roles/post_build_checks/tasks/main.yml` — New `[smoke, azure]` tagged block: conditionally PUTs a sentinel blob and DELETEs it; skipped when credentials are absent; `no_log: true` on all tasks

**Unchanged**: `export_media_worker.php`, `export_media_status.php`, `export_media_download.php`, `admin_media_lib.php`, `assets/import_progress.js`.

---

## Implementation Plan

### Phase 1 — Ansible / Infrastructure

**Step 1 — Update secrets files**

Add the three `azure_blob_*` vars to:
- `ansible/inventories/group_vars/gighive/secrets.yml`
- `ansible/inventories/group_vars/gighive2/secrets.yml`
- `ansible/inventories/group_vars/prod/secrets.yml`
- `ansible/inventories/group_vars/gighive/secrets.example.yml` (with comment block)

**Step 2 — Update `.env.j2`**

Append the three `AZURE_BLOB_*` lines to `ansible/roles/docker/templates/.env.j2`.

**Step 3 — Add Azure connectivity smoke test to `post_build_checks`**

Append a conditional `[smoke, azure]` block to `ansible/roles/post_build_checks/tasks/main.yml`.
The block runs only when all three `azure_blob_*` vars are non-empty; it is silently skipped otherwise.

```yaml
# --- Azure Blob Storage connectivity (conditional on credentials) ---

- name: Build Azure connectivity probe facts
  ansible.builtin.set_fact:
    azure_probe_url: "https://{{ azure_blob_account_name }}.blob.core.windows.net/{{ azure_blob_container }}/test-connectivity/ansible-probe-{{ ansible_facts['date_time'].epoch }}.txt?{{ azure_blob_sas_token }}"
  when:
    - azure_blob_account_name | default('') | length > 0
    - azure_blob_container    | default('') | length > 0
    - azure_blob_sas_token    | default('') | length > 0
  no_log: true
  tags: [smoke, azure]

- block:
    - name: PUT sentinel blob to Azure (connectivity check)
      ansible.builtin.uri:
        url: "{{ azure_probe_url }}"
        method: PUT
        body: "gighive-azure-connectivity-probe"
        body_format: raw
        headers:
          x-ms-blob-type: BlockBlob
          Content-Type: text/plain
          x-ms-version: "2020-04-08"
        status_code: 201
        return_content: false
      changed_when: false
      no_log: true

    - name: Assert Azure blob PUT succeeded
      ansible.builtin.debug:
        msg: "Azure Blob Storage connectivity OK — credentials valid, Write+Create confirmed"

  always:
    - name: DELETE Azure connectivity probe blob (cleanup)
      ansible.builtin.uri:
        url: "{{ azure_probe_url }}"
        method: DELETE
        status_code: [202, 404]
        return_content: false
      changed_when: false
      failed_when: false
      no_log: true

  when: azure_probe_url is defined
  tags: [smoke, azure]
```

Notes:
- `no_log: true` on every task touching `azure_probe_url` — SAS token must never appear in Ansible output.
- `always:` block ensures cleanup even when the PUT assertion fails.
- Tag `--skip-tags azure` on any run where credentials are absent and you want to suppress the skip output.

**Step 4 — Deploy to dev and verify**

Run the Ansible deploy playbook against dev. Verify env vars are present inside the container:

```bash
ssh ubuntu@devvm.gighive.internal \
  "docker exec apacheWebServer php -r \
  'echo getenv(\"AZURE_BLOB_ACCOUNT_NAME\") . PHP_EOL;'"
```

Expected: the account name string (or empty string if not yet set in `gighive2/secrets.yml`).

If Azure credentials are populated in `secrets.yml`, the `post_build_checks` role will automatically
run the Step 3 connectivity smoke test during this deploy — no manual `curl` required.

---

### Phase 2 — PHP Backend

**Step 5 — `export_media_worker_azure.php` (new)**

CLI-only (`PHP_SAPI !== 'cli'` → exit 1). Accepts `--job_id=` and `--org=` arguments.

Must `require_once __DIR__ . '/admin_media_lib.php'` to reuse `writeJobStatus()` — same as the tar worker.

Key structure:

```
1.  Parse --job_id; validate format /^[a-f0-9]{16}$/
    Parse --org= (optional; default to empty string)
2.  Resolve $jobDir, $filelistPath, $jsonPath from sys_get_temp_dir()
3.  Read Azure credentials from getenv(); fail with writeJobStatus error state if any are empty
4.  Read $rows from filelist.json; unlink it
5.  Sanitize org label: preg_replace('/[^a-zA-Z0-9_\-]/', '_', $orgArg) ?: 'all'
    Compute $batchPrefix = date('Ymd-His') . '/' . $orgLabel
    (full blob path: "gighive-export/{$batchPrefix}/{type}/{filename}")
6.  set_time_limit(0)
7.  For each $row (from filelist.json — each row has at minimum file_path, file_type, sha256_hash):
    a. $filePath = $row['file_path']  (absolute path already in row; no reconstruction needed)
       $fileType = $row['file_type']  ('audio' or 'video'; used as the blob path type segment)
       $fileName  = basename($filePath)  (e.g. abc123.mp3)
    b. Skip if !is_file($filePath) — count as $skipped
    c. Build $blobPath: "gighive-export/{$batchPrefix}/{$fileType}/{$fileName}"
    d. Build PUT URL: "https://{account}.blob.core.windows.net/{container}/{encodedPath}?{sas}"
       Blob path encoding: split on '/', rawurlencode() each segment, rejoin with '/'
       Account name and container name used verbatim (Azure enforces safe chars for both)
       *** NEVER log or echo the PUT URL — it contains the SAS token ***
    e. Open file handle: $fh = fopen($filePath, 'rb')
       curl PUT options:
         CURLOPT_PUT => true
         CURLOPT_INFILE => $fh
         CURLOPT_INFILESIZE => (int)filesize($filePath)
         CURLOPT_RETURNTRANSFER => true   ← required to capture response body for error messages
         CURLOPT_HTTPHEADER => ['x-ms-blob-type: BlockBlob', 'x-ms-version: 2020-04-08',
                                'Content-Type: application/octet-stream']
         CURLOPT_TIMEOUT => 0
         CURLOPT_FOLLOWLOCATION => false
       In a try/finally block: always fclose($fh) and curl_close($ch)
    f. Check HTTP response code: 201 = success; anything else = throw RuntimeException
       with blob name, HTTP code, and first 500 chars of response body (never the URL)
    g. Update status.json every 10 files (mirrors tar worker cadence)
    h. For video files: also upload the thumbnail blob if the thumbnail file exists.
       Thumbnail local path: derive by replacing the video dir with the thumbnails dir,
       e.g. str_replace('/video/', '/video/thumbnails/', dirname($filePath)) . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '.png'
       Blob path: "gighive-export/{$batchPrefix}/video/thumbnails/{$thumbFileName}"
       Skip silently if thumbnail file is absent (not all videos have thumbnails).
8.  On completion: writeJobStatus state=done with keys:
      blob_prefix ("gighive-export/{$batchPrefix}/"), uploaded, skipped
9.  catch Throwable: writeJobStatus state=error
```

**Step 6 — `export_media.php` modifications**

- Add `$destination = isset($_POST['destination']) ? trim($_POST['destination']) : 'local';`  
  Validate: `in_array($destination, ['local', 'azure'], true)` else default `'local'`.
- In `prepare` mode: when `$destination === 'azure'`, check that all three `AZURE_BLOB_*` env
  vars are non-empty; return HTTP 400 `{'success': false, 'error': 'Azure credentials not configured'}` if any are missing.
- In `start` mode — initial `status.json` step name must reflect the destination:
  ```php
  $initialStepName = $destination === 'azure' ? 'Upload to Azure' : 'Build archive';
  ```
  Use `$initialStepName` in the `steps` array written to `status.json`.
- In `start` mode — `proc_open` availability check applies only to the local tar path (the Azure
  worker uses curl, not `proc_open`). Gate the `function_exists('proc_open')` guard:
  ```php
  if ($destination === 'local' && !function_exists('proc_open')) { ... }
  ```
- In `start` mode — dispatch to the correct worker:
  ```php
  $workerScript = $destination === 'azure'
      ? __DIR__ . '/export_media_worker_azure.php'
      : __DIR__ . '/export_media_worker.php';

  $orgArg = $orgFilter !== '' ? ' --org=' . escapeshellarg($orgFilter) : '';
  exec('php ' . escapeshellarg($workerScript)
      . ' --job_id=' . escapeshellarg($jobId)
      . $orgArg
      . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &');
  ```
- Return `{'success': true, 'job_id': ..., 'total': ..., 'destination': $destination}` so the
  JS knows which done-state path to follow.

---

### Phase 3 — Admin UI (`admin_system.php`)

**Step 7 — Section E HTML changes**

Add a PHP block near the top of Section E that reads the Azure availability flag:

```php
<?php
$__azure_available = (
    getenv('AZURE_BLOB_ACCOUNT_NAME') !== '' && getenv('AZURE_BLOB_ACCOUNT_NAME') !== false &&
    getenv('AZURE_BLOB_CONTAINER')    !== '' && getenv('AZURE_BLOB_CONTAINER')    !== false &&
    getenv('AZURE_BLOB_SAS_TOKEN')    !== '' && getenv('AZURE_BLOB_SAS_TOKEN')    !== false
);
?>
```

Wrap the entire Destination row in a PHP conditional — no `disabled` attribute, no muted label,
no inline `.env` instruction. When credentials are absent the row is simply not emitted:

```html
<?php if ($__azure_available): ?>
<div class="row">
  <label>Destination</label>
  <div>
    <label style="margin-right:1.5em">
      <input type="radio" name="export_destination" id="export_dest_local" value="local" checked />
      Download to browser (tar.gz)
    </label>
    <label>
      <input type="radio" name="export_destination" id="export_dest_azure" value="azure" />
      Send to Azure Blob Storage
    </label>
  </div>
</div>
<?php endif; ?>
```

**Step 7b — JS changes in `doExportMedia()`**

- Read destination: `const dest = (document.querySelector('input[name="export_destination"]:checked') || {}).value || 'local';`
- Include in all fetch calls: `{ ...baseParams, mode: 'prepare', destination: dest }`
- Steps array: when `dest === 'azure'`, replace the `Download` step with
  `{ name: 'Upload to Azure', status: 'pending', message: '', progress: null }` and omit
  the `export_media_download.php` fetch entirely.
- Azure done state: render a confirmation message with the `blob_prefix` key from
  `buildResult.data.blob_prefix` (returned by the worker in `status.json`).
  Example: `"Uploaded to Azure: gighive-export/20260725-093012/all/ (42 files)"`.
- **Button label**: update `btn.textContent` dynamically based on `dest`:
  - `local`: `'Building archive\u2026'` (existing)
  - `azure`: `'Uploading to Azure\u2026'`
- **Confirm dialog text**: when `dest === 'azure'`, replace the existing local-export dialog text with:
  `'You are about to upload ' + fmtBytes(totalBytes) + ' to Azure Blob Storage. ' + skippedNote`
  Omit the free-space reminder — no local disk space is consumed on the Azure path.

---

### SonarQube / Best-Practice Notes

| Rule | Location | Finding |
|---|---|---|
| RSPEC-3776 (cognitive complexity) | `export_media_worker_azure.php` — main loop | Per-file curl setup, HTTP check, thumbnail branch, and status update all nested inside `foreach`. Extract a `uploadBlobFromFile(string $localPath, string $blobPath, ...): void` helper function to keep the loop body flat. |
| RSPEC-2635 (sensitive data in logs) | `export_media_worker_azure.php` | PUT URL contains SAS token. Worker must never `echo` or `fwrite(STDERR, ...)` the URL. Error messages use blob name + HTTP code only. |
| Brittle: 3-way credential check | `admin_system.php`, `export_media.php`, `export_media_worker_azure.php` | All three independently call `getenv()` on the same 3 vars. Acceptable for v1; candidate for a `azureCredentialsConfigured(): bool` helper in `admin_media_lib.php` in a follow-up refactor. |

---

### Phase 4 — Verification

**Step 8 — Manual verification on dev**

Prerequisites: populate `gighive2/secrets.yml` with a real Azure storage account, container, and
valid SAS token; re-run the Ansible deploy playbook to update `.env` in the container.

Verification sequence:
1. Confirm the **Step 3 Azure connectivity smoke test passed** in the Ansible deploy output
   (look for "Azure Blob Storage connectivity OK" in the `post_build_checks` role output).
2. Navigate to `https://devvm.gighive.internal/admin/admin_system.php` → Section E.
3. Confirm the **Destination row is visible** (credentials present in env causes conditional render).
4. Select Azure; leave filters blank; click **Download Archive**.
5. Confirm prepare step shows file count and size; confirm dialog; watch Upload to Azure step progress.
6. On completion, confirm the blob prefix message appears.
7. In the Azure portal (Storage browser), verify blobs exist under
   `gighive-export/{timestamp}/all/audio/` and `gighive-export/{timestamp}/all/video/`
   (or `{org}/` when an org filter was applied).
8. Verify no temp archive was created: `docker exec apacheWebServer ls /tmp | grep gighive_export`
   should show only the job state directory (no `archive.tar.gz`).
9. Verify the local tar download still works: switch radio to "Download to browser" and export a
   small filtered set; confirm file downloads correctly.

**Failure modes to test**:
- Expired or invalid SAS token → worker should fail within the first file PUT and write
  `state: error` with the HTTP response code visible in the status panel.
- Azure env vars absent → Destination row is not rendered; prepare call returns 400 if
  `destination=azure` is somehow submitted without the row being present.

**Playwright regression note**: The admin Playwright test (`admin-pages.spec.ts`) exercises Section E.
With Azure credentials absent in the dev test environment (default), the Destination row is not
rendered and the existing test flow is unchanged — no test update is required for Phase 1–3 of this
feature. If credentials are ever added to the test environment, the test will need a step to assert
the Destination row is visible and confirm the local radio remains the default.

---

## Rollback

All changes are additive. To roll back:
- Remove the radio group HTML from Section E in `admin_system.php` (restores single-button behavior)
- Remove the `destination` param handling from `export_media.php` (ignored if absent — no effect on existing callers)
- Remove `export_media_worker_azure.php` (never called if `destination` param is absent)
- Remove the three `AZURE_BLOB_*` lines from `.env.j2` and `secrets.yml` files
- Remove the `[smoke, azure]` block from `post_build_checks/tasks/main.yml`

No database schema changes, no file moves, no destructive side effects.

---

## Assumptions

- PHP `curl` extension is available and enabled in the `apacheWebServer` container
  (confirmed: curl 8.5.0 on dev, 2026-07-25).
- All media files are within Azure's 5 GiB single-PUT block blob limit. Files above this
  limit are an edge case not handled in v1; the worker will receive an HTTP 413 and fail
  cleanly with an error message.
- The SAS token has `Write` and `Create` permissions on the configured container.
- The admin is responsible for SAS token expiry management; no expiry warning is shown in the UI.

---

## Future Considerations

- **Restore from Azure**: list blobs under a chosen prefix, download each into the bind-mounted
  volume. Natural companion feature to this export.
- **Multi-provider support**: introduce `BLOB_PROVIDER=azure|s3|b2` env var and an abstraction
  layer (`BlobUploader` interface) to avoid duplicating the `curl PUT` logic per provider.
- **SaaS alignment**: when tenant-scoped file paths land (Step 10 in SaaS plan), the blob prefix
  structure gains a `tenant_id` component: `gighive-export/{tenant_id}/{timestamp}/...`.
