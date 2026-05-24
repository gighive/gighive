# Refactor: Pull TUS client options into group_vars

## Problems

### 1. `retryDelays` hardcoded

`retryDelays:[0,1000,3000]` is hardcoded in the JavaScript TUS client instantiation at line 981 of `admin/admin_database_load_import_media_from_folder.php`. This makes it impossible to tune the retry window per environment without a code change.

During large uploads (e.g. 6000 videos), network blips can cause one or more TUS upload slots to stall. The tus-js-client will retry automatically using the `retryDelays` sequence. If the blip lasts longer than the total retry window (currently 4 seconds: 0+1+3), all retries exhaust and the slot is marked as a retryable failure. The user must then manually click "Retry Upload".

With a wider retry window (e.g. `[0, 1000, 3000, 10000, 30000]` = 44 seconds total), transient network blips self-heal without operator intervention.

### 2. `removeFingerprintOnSuccess` not set — localStorage quota exhaustion

tus-js-client stores a resumable upload fingerprint (URL + state) in `localStorage` for every file so it can resume from the last byte. With large batches (e.g. 6000 files), these entries accumulate and exhaust the browser's ~5–10 MB `localStorage` quota for the origin.

Symptom: `QuotaExceededError: Failed to execute 'setItem' on 'Storage'` — new uploads cannot save state and fail immediately as retryable errors.

Fix: set `removeFingerprintOnSuccess: true` in the tus-js-client options. This removes each file's localStorage entry as soon as the upload completes successfully, preventing accumulation entirely.

**Immediate workaround (before fix is deployed):** DevTools → Application → Local Storage → `https://<host>` → Clear All, then click Retry Upload.

---

## Root Cause

Both values are hardcoded in JS with no path to configure them per environment. The existing `tus_client_chunk_size_bytes` variable demonstrates the correct pattern: group_vars → `.env.j2` → PHP `getenv()` → rendered into JS.

---

## Plan (3 files)

### 1. `ansible/inventories/group_vars/gighive/gighive.yml`

Add after `tus_client_chunk_size_bytes`:

```yaml
# tus-js-client retry delay sequence (ms). Extends auto-retry window for network blips.
tus_client_retry_delays: [0, 1000, 3000]

# Remove localStorage fingerprint on successful upload to prevent quota exhaustion on large batches.
tus_client_remove_fingerprint_on_success: true
```

### 2. `ansible/roles/docker/templates/.env.j2`

Add after the `TUS_CLIENT_CHUNK_SIZE_BYTES` line:

```bash
TUS_CLIENT_RETRY_DELAYS_JSON={{ tus_client_retry_delays | default([0, 1000, 3000]) | to_json }}
TUS_CLIENT_REMOVE_FINGERPRINT_ON_SUCCESS={{ (tus_client_remove_fingerprint_on_success | default(true)) | ternary('true', 'false') }}
```

### 3. `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php`

**PHP top section** — add after the `$__audio_exts`/`$__video_exts` block (before `?>`):

```php
$tusRetryDelaysJson = getenv('TUS_CLIENT_RETRY_DELAYS_JSON');
if (!is_string($tusRetryDelaysJson) || trim($tusRetryDelaysJson) === '') $tusRetryDelaysJson = '[0,1000,3000]';
$__tus_retry_delays = json_decode($tusRetryDelaysJson, true);
if (!is_array($__tus_retry_delays)) $__tus_retry_delays = [0, 1000, 3000];
$__tus_retry_delays_js = json_encode(array_values(array_map('intval', $__tus_retry_delays)));

$__tus_remove_fingerprint = filter_var(getenv('TUS_CLIENT_REMOVE_FINGERPRINT_ON_SUCCESS') ?: 'true', FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
```

**JS inline** — replace the hardcoded value at line 981:

```js
// Before:
retryDelays:[0,1000,3000],

// After:
retryDelays:<?= $__tus_retry_delays_js ?>,
removeFingerprintOnSuccess:<?= $__tus_remove_fingerprint ?>,
```

---

## Extending the retry window

After deploying the refactor, update the group_vars value to widen the window and re-run Ansible (`--tags docker`):

```yaml
tus_client_retry_delays: [0, 1000, 3000, 10000, 30000]
```

This gives 5 attempts over 44 seconds, covering most transient network blips without operator intervention.

---

## Files Changed

| File | Change |
|------|--------|
| `ansible/inventories/group_vars/gighive/gighive.yml` | Add `tus_client_retry_delays`, `tus_client_remove_fingerprint_on_success` |
| `ansible/roles/docker/templates/.env.j2` | Add `TUS_CLIENT_RETRY_DELAYS_JSON`, `TUS_CLIENT_REMOVE_FINGERPRINT_ON_SUCCESS` |
| `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php` | Read env vars in PHP, render into JS `retryDelays` + `removeFingerprintOnSuccess` |

## Testing

1. Deploy with `ansible-playbook site.yml --tags docker`
2. Verify env vars are present in the container:
   ```bash
   docker exec apacheWebServer printenv | grep TUS_CLIENT
   ```
3. Load the admin upload page, view page source, confirm `retryDelays` and `removeFingerprintOnSuccess` match group_vars values
4. Run a batch upload of 10+ files; after completion confirm localStorage for the origin is empty (DevTools → Application → Local Storage)
5. Optionally set `tus_client_retry_delays: [0, 100]` in dev group_vars to verify a short window takes effect

---

## Debugging Commands

Container name: `apacheWebServer`

**Check TUS client env vars are set correctly:**
```bash
docker exec apacheWebServer printenv | grep TUS_CLIENT
```

**Check for any current retryable failures in upload status:**
```bash
docker exec apacheWebServer find /var/www/private/tus-hooks -name "upload_status.json" | xargs grep -l "failed" 2>/dev/null
```

**Inspect failed file details:**
```bash
docker exec apacheWebServer find /var/www/private/tus-hooks -name "upload_status.json" \
  -exec sh -c 'grep -q "failed" "$1" && cat "$1"' _ {} \; 2>/dev/null \
  | python3 -m json.tool 2>/dev/null | grep -A5 '"state": "failed"'
```

**Check TUS and Apache logs for errors:**
```bash
docker logs apacheWebServer --since 1h 2>&1 | grep -iE "error|timeout|fatal" | tail -50
```

**localStorage quota workaround (browser-side, before fix is deployed):**
DevTools → Application → Local Storage → `https://<host>` → Clear All → click Retry Upload

---

## AI Worker: ffmpeg Error Capture Bugs (discovered during 5,800-file upload run)

These bugs were found by inspecting the `ai_jobs` table after a large overnight upload session. They have been fixed in `frame_extractor.py`.

### Bug 1: stderr truncated to banner only

**File:** `ansible/roles/ai_worker/files/ai-worker/frame_extractor.py`

The original code used `stderr[:512]` to capture the ffmpeg error. ffmpeg always prints a version/config banner first (~450 chars), so the stored `error_msg` was entirely banner with no actual error:

```python
# Before (broken — captures banner, not the error)
raise MediaDecodeError(f"ffmpeg failed (exit {ffmpeg_result.returncode}): {ffmpeg_result.stderr[:512]}")

# After (fixed — captures last 20 lines where the actual error lives)
raise MediaDecodeError(f"ffmpeg failed (exit {ffmpeg_result.returncode}): {'\n'.join(ffmpeg_result.stderr.splitlines()[-20:])}")
```

Same fix applied to the `ffprobe` error capture on the line above it.

### Bug 2: UTF-8 decode crash on non-ASCII video metadata

`subprocess.run(..., text=True)` uses strict UTF-8 decoding by default. Video files with non-ASCII metadata (e.g. Japanese characters in `Tokyo-Reality-h264-1080p.mp4`) caused a hard Python crash:

```
'utf-8' codec can't decode byte 0x8e in position 2510: invalid start byte
```

Fix: added `errors='replace'` to both subprocess calls so non-ASCII bytes are substituted with `?` instead of crashing.

### Permanently-failing file types (not bugs — expected)

After fixing the above, these file categories will still fail because ffmpeg cannot process them:

| File type | Reason | Example |
|-----------|--------|---------|
| `.VOB` (DVD VIDEO_TS) | CSS-encrypted DVD — requires `libdvdcss` | `VIDEO_TS/VTS_01_0.VOB` |
| `.m2v` (MPEG-2 elementary stream) | No container — ffprobe cannot determine duration | `output.m2v`, `reframe.m2v` |
| Very old `.avi` (pre-2000) | Codec not supported in current ffmpeg build | `chefjohnson/*.avi` |

These should be left as `failed` — they are not retryable.

### Query to inspect failed jobs with file paths

```bash
docker exec mysqlServer mysql -u root -p"" music_db \
  -e "SELECT j.id, j.updated_at, LEFT(j.error_msg,80) AS error, a.source_relpath FROM ai_jobs j JOIN assets a ON a.asset_id=j.target_id WHERE j.status='failed' ORDER BY j.id;" 2>/dev/null | grep -v Warning
```

### Reset retryable failed jobs after fix is deployed

```bash
docker exec mysqlServer mysql -u root -p"" music_db \
  -e "UPDATE ai_jobs SET status='queued', attempts=0, error_msg=NULL WHERE status='failed' AND error_msg NOT LIKE '%VOB%' AND error_msg NOT LIKE '%.m2v%';" 2>/dev/null | grep -v Warning
```
