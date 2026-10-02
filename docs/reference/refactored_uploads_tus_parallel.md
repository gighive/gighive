# Refactor: TUS Parallel Chunk Uploads (`tus_client_parallel_uploads`)

## Problem

The TUS upload loop in `admin_database_load_import_media_from_folder.php` uploads each file
sequentially with a single chunk stream (`parallelUploads` defaults to 1 in tus-js-client).
On a Gigabit LAN with an average file size of ~307 MB, each file spends ~2.5 seconds in
network transfer and ~3–6 seconds in server-side processing (thumbnail + DB write + TUS hook).
Both phases run one file at a time with the network link mostly idle during server processing.

## What `parallelUploads` Does

`parallelUploads: N` (a built-in tus-js-client option) splits a **single file** into N equal
parts, uploads each part as a separate TUS sub-upload concurrently, then sends a concatenation
request to merge them on the server. This overlaps the chunk transfer phase across the network
link, reducing per-file transfer time proportionally.

**This is per-file parallelism — not multi-file concurrency.** The next file does not start
until the current file's concatenation completes.

### Requirements (both already met)
- HTTP/2 enabled on Apache — confirmed (`Protocols h2 http/1.1` in `default-ssl.conf.j2`)
- tusd `concatenation` extension — supported by tusd out of the box

---

## Expected Gain

| `parallelUploads` | Transfer time per 307 MB file (est.) | Whole-batch improvement (6,200 files) |
|---|---|---|
| 1 (current) | ~2.5 sec | baseline |
| 2 | ~1.3 sec | ~15–20 min saved |
| 3 | ~0.9 sec | ~25–30 min saved |
| 4 | ~0.7 sec | ~30–35 min saved |

> Transfer is only ~2.5 sec of an ~8–11 sec total per-file cycle; server processing dominates.
> Savings are real but modest (~2–3% of wall-clock time on LAN).
> Over WAN (higher RTT), the gain is proportionally larger.

---

## Files to Change

| File | Change |
|------|--------|
| `ansible/inventories/group_vars/gighive/gighive.yml` | Add `tus_client_parallel_uploads: 3` |
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | Same |
| `ansible/inventories/group_vars/prod/prod.yml` | Same |
| `ansible/roles/docker/templates/.env.j2` | Add `TUS_CLIENT_PARALLEL_UPLOADS` env var |
| `admin/admin_database_load_import_media_from_folder.php` | Read env var (PHP) + inject into `tus.Upload` options (JS) |

---

## Plan (5 edits across 3 distinct files)

### 1. `group_vars/gighive/gighive.yml`, `gighive2/gighive2.yml`, `prod/prod.yml`

Insert after `tus_client_remove_fingerprint_on_success: true` (line 298, identical in all 3):

```yaml
# Parallel chunk streams per file (tus-js-client parallelUploads).
# Splits one file into N concurrent TUS sub-uploads then concatenates server-side.
# Requires HTTP/2 (already enabled) and tusd concatenation extension (supported).
# 1 = sequential (default). 3 = recommended for LAN. 2 = recommended for WAN.
tus_client_parallel_uploads: 3
```

### 2. `ansible/roles/docker/templates/.env.j2`

Insert after `TUS_CLIENT_REMOVE_FINGERPRINT_ON_SUCCESS=...`:

```jinja
TUS_CLIENT_PARALLEL_UPLOADS={{ tus_client_parallel_uploads | default(1) | int }}
```

### 3. `admin/admin_database_load_import_media_from_folder.php` — PHP block (top of file)

Insert after the `$__tus_remove_fingerprint` line:

```php
$__tus_parallel_uploads = max(1, (int)(getenv('TUS_CLIENT_PARALLEL_UPLOADS') ?: '1'));
```

### 4. `admin/admin_database_load_import_media_from_folder.php` — JS `tus.Upload` options (~line 1000)

```js
endpoint:'/files/',
retryDelays:<?= $__tus_retry_delays_js ?>,
removeFingerprintOnSuccess:<?= $__tus_remove_fingerprint ?>,
parallelUploads:<?= $__tus_parallel_uploads ?>,
metadata,
chunkSize:8*1024*1024,
```

---

## Testing

The goal is to verify whether `parallelUploads: 3` measurably reduces per-file transfer time
and total batch time versus `parallelUploads: 1` (baseline).

### Baseline Measurement (before implementing)

Pick a small controlled batch of 20–30 large files (>200 MB each) from your archive.

1. Set `tus_client_parallel_uploads: 1` in group_vars (or leave unset — default is 1).
2. Run Ansible to deploy, confirm `TUS_CLIENT_PARALLEL_UPLOADS=1` in the container:
   ```bash
   docker exec apacheWebServer env | grep TUS_CLIENT_PARALLEL
   ```
3. Open the upload UI, select the test batch, click **Upload Media**.
4. Note the start time. Watch the upload debug log or browser Network tab.
5. After completion, note the end time and calculate:
   - **Total elapsed time**
   - **Files/minute** (file count ÷ elapsed minutes)
   - **MB/s** (total GB × 1024 ÷ elapsed seconds)
6. Optional — time a single large file precisely:
   ```bash
   # In browser Network tab: filter by /files/, sort by duration,
   # note longest single-file PATCH sequence duration.
   ```

### After Measurement (with `parallelUploads: 3`)

1. Set `tus_client_parallel_uploads: 3`, run Ansible to deploy.
2. Confirm in container:
   ```bash
   docker exec apacheWebServer env | grep TUS_CLIENT_PARALLEL
   ```
3. Repeat the **identical batch** (same files). The Resume Upload fix ensures they are
   re-uploaded cleanly — first clear the job or use a fresh job with the same folder.
4. Note the same three metrics: elapsed time, files/minute, MB/s.

### What to Compare

| Metric | Baseline (N=1) | Parallel (N=3) | Expected delta |
|--------|---------------|----------------|----------------|
| Total elapsed time | _measured_ | _measured_ | ~5–15% faster on LAN |
| Files/minute | _measured_ | _measured_ | Slight increase |
| MB/s (transfer only) | _measured_ | _measured_ | Up to ~2–3× per file |
| Server errors / concat failures | 0 | _check_ | Should be 0 |
| Upload trace `tus_upload_progress` events | N per file | N×3 sub-uploads | More entries, all expected |

### Pass / Fail Criteria

| Check | Pass |
|-------|------|
| All files reach `db_done` or `thumbnail_done` | Yes |
| No `concat_failed` or `TUS_EXTENSION_NOT_SUPPORTED` errors in tusd log | Clean |
| Total elapsed ≤ baseline | Confirmed improvement |
| Upload debug trace shows no unexpected errors | Clean |

### Checking tusd logs for concatenation errors

```bash
docker logs tusdServer 2>&1 | grep -i "concat\|error\|fail" | tail -20
```

### Reverting if needed

Set `tus_client_parallel_uploads: 1` in group_vars and re-run Ansible. No data is affected —
this is a client-side upload option only.

---

## Future MCP Tool — `get_upload_throughput_stats`

The manual measurement steps in the Testing section above (noting start/end times,
calculating files/min and MB/s by hand, watching the browser Network tab) are exactly
the kind of operational work that belongs in an MCP tool:

```
get_upload_throughput_stats(job_id) → {files_total, files_done, elapsed_seconds,
                                        files_per_min, mb_per_sec, avg_file_size_mb}
```

**Why it isn't in the initial MCP server implementation:** The current schema has no
upload session timing record. `assets` has `file_size_bytes` but no upload job identifier
per row and no transfer-time vs. ingestion-time distinction. Building this tool requires
one of:
- An `upload_sessions` table: `(job_id, start_time, end_time, file_count, total_bytes)` —
  one row per upload job, written by the PHP TUS finalization hook when the last file
  completes
- Or per-asset upload timing columns: `upload_started_at`, `upload_completed_at` on `assets`

The `upload_sessions` option is simpler and aligns with the existing `get_upload_job_state`
tool (which already reconciles the job via the PHP endpoint).

This is tracked as **Deferred 2** in `docs/feature_mcp_server_beneficial.md`.

---

## Related Docs

- `docs/guide_upload_estimated_times.md` — empirical upload time data and optimization table
- `docs/refactored_tus_retry_delays_and_frame_extractor.md` — previous TUS client config refactor
