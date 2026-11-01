# Async Worker Pattern

Reusable pattern for long-running PHP operations that need live progress feedback without blocking an HTTP request.

---

## Pattern: Background PHP Worker + Job Directory + Browser Polling

### Problem

Some admin operations take tens of seconds to minutes and cannot be expressed as a single synchronous HTTP request:

- The browser's connection will time out or show no feedback for the duration.
- PHP's `max_execution_time` may cut the request short.
- There is no way to report per-item progress mid-response.

Examples: building a large ZIP archive, extracting and hashing hundreds of media files, scanning a staging directory and ingesting DB stubs.

### Solution

Split the operation into three PHP components and one JS client:

1. **Start endpoint** — validates input, creates a job directory, writes an initial `status.json`, spawns the worker via `exec(...&)`, and returns a `job_id` immediately (sub-second).
2. **Background worker** — a CLI PHP script that does the long-running work, writing progress into `status.json` every N items.
3. **Status endpoint** — reads `status.json` and returns a `steps` array consumable directly by `renderImportStepsShared()`.
4. **JS client** — calls `pollJobStatus()` every 1500 ms, renders the live bar, and acts on the `done` or `error` state.

### How it works (step by step)

#### 1. Job directory

Each job gets an isolated directory under `sys_get_temp_dir()`. All job files live inside it — easy to inspect, easy to clean up atomically.

```
sys_get_temp_dir()/gighive_{type}_{job_id}/
  status.json    ← written by start endpoint; updated by worker
  {input file}   ← e.g. filelist.json (export) or upload.zip (import)
  {output file}  ← e.g. archive.zip; retained until downloaded
```

Naming convention: `gighive_{type}_{job_id}` where `{type}` is a short noun (`export`, `import`, `iphone_import`, etc.) and `{job_id}` is `bin2hex(random_bytes(8))` — 16 lowercase hex chars.

#### 2. Start endpoint

```php
// Generate job ID and create directory
$jobId  = bin2hex(random_bytes(8));
$jobDir = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/';
mkdir($jobDir, 0700, true);

// Write initial status.json with LOCK_EX
file_put_contents($jobDir . 'status.json', json_encode([
    'success'    => true,
    'job_id'     => $jobId,
    'state'      => 'running',
    'updated_at' => date('c'),
    // ... job-specific initial fields
], JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

// Check exec() availability before spawning
if (!function_exists('exec')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'exec() is disabled; background worker cannot be spawned']);
    exit;
}

// Spawn worker — named --job_id= arg, stdout/stderr suppressed, backgrounded
exec('php ' . escapeshellarg(__DIR__ . '/my_worker.php') . ' --job_id=' . escapeshellarg($jobId) . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &');

echo json_encode(['success' => true, 'job_id' => $jobId]);
```

#### 3. Worker script boilerplate

```php
<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

// Parse --job_id= named arg
$jobId = '';
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--job_id=')) {
        $jobId = substr((string)$arg, strlen('--job_id='));
    }
}
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    fwrite(STDERR, "my_worker: invalid or missing --job_id\n");
    exit(1);
}

$jobDir   = sys_get_temp_dir() . '/gighive_{type}_' . $jobId . '/';
$jsonPath = $jobDir . 'status.json';

// $writeStatus closure — LOCK_EX makes every write atomic w.r.t. concurrent readers
$writeStatus = function(array $payload) use ($jsonPath): void {
    $payload['updated_at'] = date('c');
    @file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
};

set_time_limit(0);

try {
    // ── Step N: do work ──────────────────────────────────────────────────────
    $steps[0]['status']   = 'running';
    $steps[0]['progress'] = ['processed' => 0, 'total' => $total];
    $writeStatus(['state' => 'running', 'steps' => $steps]);

    foreach ($items as $item) {
        $processed++;
        // ... process item ...
        if ($processed % 10 === 0) {
            $steps[0]['progress'] = ['processed' => $processed, 'total' => $total];
            $writeStatus(['state' => 'running', 'steps' => $steps]);
        }
    }

    $steps[0]['status'] = 'ok';
    $writeStatus(['state' => 'done', 'completed_at' => date('c'), 'steps' => $steps]);

} catch (Throwable $e) {
    error_log('my_worker: fatal: ' . $e->getMessage());
    @file_put_contents($jsonPath, json_encode([
        'success'       => false,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => $e->getMessage(),
        'updated_at'    => date('c'),
        'steps'         => $steps,
    ], JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    exit(1);
}
```

**Key conventions:**
- `declare(strict_types=1)` — always.
- `PHP_SAPI !== 'cli'` guard — prevents the worker from being called over HTTP.
- `--job_id=` named arg — more robust than positional; consistent with `iphone_import_worker.php`.
- `$writeStatus` closure captures `$jsonPath` and stamps `updated_at` on every call.
- `LOCK_EX` on every write — atomic with respect to the status endpoint reading the file; eliminates partial-read corrupted JSON.
- `try { ... } catch (Throwable $e)` wraps all work — the worker always writes an error state on fatal exceptions, so the browser never polls forever.
- `set_time_limit(0)` — CLI processes inherit PHP's default; be explicit.

#### 4. Status JSON format

Every `status.json` follows this shape — the `steps` array is consumed directly by `renderImportStepsShared()` without transformation:

```json
{
  "success": true,
  "job_id": "a3f8c2d91e4b7f05",
  "state": "running",
  "updated_at": "2026-06-21T14:22:10+00:00",
  "steps": [
    {
      "name": "Step label shown in UI",
      "status": "running",
      "message": "847 / 2341 files",
      "progress": { "processed": 847, "total": 2341 }
    }
  ]
}
```

`state` values:

| Value | Meaning |
|---|---|
| `running` | Worker is actively processing |
| `finalizing` | Work is done but a non-reportable I/O step is in progress (e.g. `ZipArchive::close()`) |
| `done` | Worker completed successfully; add `"completed_at": date('c')` |
| `error` | Worker failed; add `"error_message": "..."` |

`step.status` values match `renderImportStepsShared()` expectations: `pending`, `running`, `ok`, `error`.

#### 5. Status endpoint

```php
// Validate job_id
if (!preg_match('/^[a-f0-9]{16}$/', $jobId ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid job_id']);
    exit;
}

$jobDir   = sys_get_temp_dir() . '/gighive_{type}_' . $jobId . '/';
$jsonPath = $jobDir . 'status.json';

if (!is_file($jsonPath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Job not found']);
    exit;
}

$data = json_decode(file_get_contents($jsonPath), true);

// Stale detection — use updated_at from JSON, not filemtime()
if (($data['state'] ?? '') === 'running' && isset($data['updated_at'])) {
    $age = (new DateTime())->getTimestamp() - (new DateTime($data['updated_at']))->getTimestamp();
    if ($age > 3600) {
        // Worker crashed or exec() failed silently — clean up and report error
        array_map('unlink', glob($jobDir . '*'));
        @rmdir($jobDir);
        echo json_encode([
            'success' => true,
            'state'   => 'error',
            'steps'   => [['name' => 'Job', 'status' => 'error',
                           'message' => 'Worker timed out or failed to start — verify exec() is enabled in PHP']],
        ]);
        exit;
    }
}

// Pass steps through directly — already in renderImportStepsShared() format
echo json_encode([
    'success' => true,
    'state'   => $data['state'] ?? 'running',
    'steps'   => $data['steps'] ?? [],
]);
```

**Stale detection uses `updated_at` from the JSON** (not `filemtime()`). `filemtime()` is filesystem-dependent and may not reflect the last logical write time. `updated_at` is written by `$writeStatus` on every call and is always correct.

#### 6. JS polling — `pollJobStatus()`

`pollJobStatus()` lives in `admin/assets/import_progress.js` alongside `renderImportStepsShared()`:

```js
/**
 * Poll a status endpoint and render steps via renderImportStepsShared().
 * @param {string}   jobId       - job_id returned by the start endpoint
 * @param {string}   statusUrl   - URL to poll (job_id appended as ?job_id=...)
 * @param {Element}  stepsEl     - DOM element to render steps into
 * @param {Function} onDone      - Called with (state, data) when state is 'done' or 'error'
 * @param {number}   intervalMs  - Poll interval in ms (default 1500)
 * @returns {{ stop: Function }}
 */
function pollJobStatus(jobId, statusUrl, stepsEl, onDone, intervalMs = 1500) { ... }
```

Usage:

```js
const { stop } = pollJobStatus(jobId, 'my_status.php', statusEl, (state, data) => {
    if (state === 'done') {
        // handle completion — e.g. trigger download, show summary
    } else {
        // handle error — data.steps contains the error step
    }
});
```

`pollJobStatus()` calls `renderImportStepsShared(data.steps, { showProgressBar: true })` on every poll tick. The caller does not need to manage rendering.

---

### Security checklist

- **Auth guard on all three endpoints** — `$user === 'admin'` check before any logic; return HTTP 403 JSON if not admin.
- **`job_id` validation** — always validate against `/^[a-f0-9]{16}$/` before constructing any file paths from it. Never use user-supplied input directly in a path.
- **`PHP_SAPI !== 'cli'` in worker** — prevents the worker from being invoked via HTTP even if Apache misconfiguration exposes the `admin/` directory.
- **`function_exists('exec')` check** — return HTTP 500 with a descriptive error before creating any job directory or files if `exec()` is disabled.
- **Job directory permissions** — `mkdir($jobDir, 0700, true)` — no world-read.
- **Cleanup on error** — the catch block always writes an error state; the status endpoint cleans up stale job directories.

---

### Current implementations

| Worker | Start endpoint | Status endpoint | Special notes |
|---|---|---|---|
| `admin/iphone_import_worker.php` | `admin/iphone_import_server_scan.php` | (via `pollManifestJob()` / manifest status path) | Original implementation; uses separate `result.json` alongside `status.json` |
| `admin/export_media_worker.php` *(planned Phase 1)* | `admin/export_media.php` mode=`start` | `admin/export_media_status.php` | Adds `export_media_download.php` for pre-built ZIP streaming |
| `admin/import_media_zip_worker.php` *(planned Phase 2)* | `admin/import_media_zip.php` mode=`start` | `admin/import_media_zip_status.php` | Two-phase prepare+start; no download step |

---

### When to use this pattern

- The operation takes more than ~5 seconds at expected scale.
- Per-item progress is meaningful to show (N of M files, N of M rows).
- The operation cannot be split into smaller synchronous chunks without losing atomicity.
- PHP `max_execution_time` would be exceeded by a synchronous approach.

### When NOT to use this pattern

- The operation is fast enough to complete within a single HTTP request (<5s).
- Progress doesn't matter to the operator (e.g. a single-file rename).
- `exec()` is known to be disabled in the target environment — check with `function_exists('exec')` first and provide a fallback or clear error.
- The operation requires a return value that must be acted on immediately (use synchronous call instead).
