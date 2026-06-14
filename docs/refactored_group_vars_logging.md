# Refactor: Expose Upload Trace Limits and Concurrency in group_vars

## Problem

Three upload-related tuning values are currently hardcoded in source files with no way to adjust them per environment without editing code:

| Value | Current location | Hardcoded as |
|---|---|---|
| Client-side trace log cap | `admin_database_load_import_media_from_folder.php` `pushClientTrace` + `mergeServerTrace` | `400` (literal, twice) |
| Server-side trace log cap | `import_manifest_lib.php` `gighive_manifest_read_upload_trace` | `300` (literal) |
| TUS parallel upload workers | `admin_database_load_import_media_from_folder.php` `sectionStartUpload` | `const UPLOAD_CONCURRENCY = 3` (local const) |

The env var `TUS_CLIENT_PARALLEL_UPLOADS` is already read in the PHP header (`$__tus_parallel_uploads`, line 26) and in `.env.j2` (line 37), and `tus_client_parallel_uploads: 3` is already set in all three group_vars files — but it is never injected into the JS — so the env var has no effect.

For large jobs (6,500+ files) the trace cap is a meaningful memory control knob. Parallel upload concurrency is a bandwidth/server-load knob. Both deserve per-environment tunability.

---

## Files Affected (6 total)

1. `ansible/inventories/group_vars/gighive2/gighive2.yml` — add `# upload log tuning` section
2. `ansible/inventories/group_vars/gighive/gighive.yml` — add `# upload log tuning` section
3. `ansible/inventories/group_vars/prod/prod.yml` — add `# upload log tuning` section
4. `ansible/roles/docker/templates/.env.j2` — add two new env var lines
5. `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php` — PHP header + JS constants + two `400` replacements + remove local `UPLOAD_CONCURRENCY`
6. `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php` — env-based limit in `gighive_manifest_read_upload_trace`

---

## Files to Change

### 1. `ansible/inventories/group_vars/gighive2/gighive2.yml` (dev)
Insert a new section immediately after `tus_client_parallel_uploads` (line 306):
```yaml
# --------------------------------------------------------------------
# upload log tuning
# --------------------------------------------------------------------
# Max trace entries kept in JS memory (client) and read from upload_trace.jsonl (server).
# Increase for deeper debugging of large jobs; decrease to reduce browser memory on 6500+ file batches.
upload_trace_max_client: 400
upload_trace_max_server: 300
```

### 2. `ansible/inventories/group_vars/gighive/gighive.yml` (lab + staging)
Same new section immediately after `tus_client_parallel_uploads` (line 306):
```yaml
# --------------------------------------------------------------------
# upload log tuning
# --------------------------------------------------------------------
# Max trace entries kept in JS memory (client) and read from upload_trace.jsonl (server).
# Increase for deeper debugging of large jobs; decrease to reduce browser memory on 6500+ file batches.
upload_trace_max_client: 400
upload_trace_max_server: 300
```

### 3. `ansible/inventories/group_vars/prod/prod.yml` (prod)
Same new section immediately after `tus_client_parallel_uploads` (line 306):
```yaml
# --------------------------------------------------------------------
# upload log tuning
# --------------------------------------------------------------------
# Max trace entries kept in JS memory (client) and read from upload_trace.jsonl (server).
# Increase for deeper debugging of large jobs; decrease to reduce browser memory on 6500+ file batches.
upload_trace_max_client: 400
upload_trace_max_server: 300
```

### 4. `ansible/roles/docker/templates/.env.j2`
Add two new lines (alongside existing `TUS_CLIENT_PARALLEL_UPLOADS`), using `| int` to match the existing style:
```
UPLOAD_TRACE_MAX_CLIENT={{ upload_trace_max_client | default(400) | int }}
UPLOAD_TRACE_MAX_SERVER={{ upload_trace_max_server | default(300) | int }}
```
`TUS_CLIENT_PARALLEL_UPLOADS` already exists at line 37 — no change needed there.

### 5. `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php`

**PHP header block** — insert on a new line after `$__tus_parallel_uploads` (line 26) and **before** the closing `?>` (line 27):
```php
$__upload_trace_max_client = max(50, (int)(getenv('UPLOAD_TRACE_MAX_CLIENT') ?: '400'));
```

**JS PHP-injected constants block** (lines 207–211) — add two constants and wire the existing parallel uploads var:
```javascript
const UPLOAD_TRACE_MAX  = <?= $__upload_trace_max_client ?>;
const UPLOAD_CONCURRENCY = <?= $__tus_parallel_uploads ?>;
```

**`pushClientTrace`** (line 350) — replace hardcoded `400` with `UPLOAD_TRACE_MAX`:
```javascript
if(s.uploadTrace.length > UPLOAD_TRACE_MAX) s.uploadTrace = s.uploadTrace.slice(-UPLOAD_TRACE_MAX);
```

**`mergeServerTrace`** (line 365) — same replacement:
```javascript
if(s.uploadTrace.length > UPLOAD_TRACE_MAX) s.uploadTrace = s.uploadTrace.slice(-UPLOAD_TRACE_MAX);
```

**`sectionStartUpload`** (line 795) — remove the local const (now superseded by the module-level PHP-injected one):
```javascript
// DELETE: const UPLOAD_CONCURRENCY = 3;
```

### 6. `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php`

**`gighive_manifest_read_upload_trace`** — read limit from env instead of accepting a hardcoded default:
```php
function gighive_manifest_read_upload_trace(string $jobDir, int $limit = 0): array {
    if ($limit <= 0) $limit = max(50, (int)(getenv('UPLOAD_TRACE_MAX_SERVER') ?: '300'));
    ...
}
```

---

## Testing

After container rebuild:
1. Verify upload jobs still start correctly (concurrency wiring)
2. Verify trace log scrolls correctly and caps at configured limit
3. Optionally: temporarily set `upload_trace_max_client: 5` in dev group_vars, rebuild, confirm log truncates at 5 entries

---

## Implementation Notes

- **Line number drift**: adding the new PHP var before `?>` in file 5 shifts all subsequent line numbers in that file by +1. Line numbers in the steps below are pre-edit guidance only; the edit tool matches by string content, not line number, so this does not affect correctness.
- **Atomic pair**: adding `const UPLOAD_CONCURRENCY = <?= $__tus_parallel_uploads ?>;` to the JS constants block and deleting `const UPLOAD_CONCURRENCY = 3;` from `sectionStartUpload` must be done in the same editing session so no intermediate broken state is committed.

## Notes

- All three group_vars files get the same defaults as the current hardcoded values — behaviour is unchanged until a value is explicitly overridden
- `max(50, ...)` guards prevent accidentally setting the limit so low that the log becomes useless
- `tus_client_parallel_uploads: 3` is **already present** in all three group_vars files — no change needed there; only the JS wiring is missing
- The two new trace vars live in a new `# upload log tuning` section inserted immediately after `tus_client_parallel_uploads`, following the same `# ----` banner style used throughout the file
