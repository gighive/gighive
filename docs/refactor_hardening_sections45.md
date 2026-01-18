# Refactor/Hardening Ideas for Admin Sections 4 & 5

This document captures follow-up hardening ideas for the Section 4/5 async manifest import pipeline.

Goal: improve reliability, debuggability, and safety for large imports without changing the user-facing workflow.

## Summary of current design

- Admin Sections 4/5 hash files client-side and upload a JSON manifest.
- Server creates an async job and runs a background worker.
- UI polls job status and renders steps, progress, and final results.
- Jobs are persisted under `/var/www/private/import_jobs/<job_id>/` with `manifest.json`, `meta.json`, `status.json`, and `result.json`.
- A worker lock prevents concurrent workers; a DB lock prevents concurrent imports.

## Recommended hardening work (future)

### 1) Input limits and early rejection

Why: prevent runaway memory/CPU/disk usage from very large manifests or malformed payloads.

- **Max request size (bytes)** on async endpoints.
  - Check `Content-Length` when present and/or cap `strlen($rawBody)` after reading the body.
  - Return `413 Payload Too Large` with a clear JSON error.
- **Max item count** for `items[]`.
  - Reject if beyond a configured cap.
  - Provide a clear message (e.g. “Too many files; split into smaller batches.”).
- **Field length caps**.
  - Sanity limits for `file_name`, `source_relpath`, and other strings.
- **Numeric bounds validation**.
  - Ensure `size_bytes` is valid (finite, non-negative, not absurdly large).
- **Disk space guardrail**.
  - Before writing job files, optionally check free disk space under `/var/www/private/`.

### 2) Lock resilience and stale-lock recovery

Why: avoid a “wedged” system where a crash leaves a lock behind and all new jobs are blocked.

- **Worker lock metadata**.
  - Write lock metadata (pid/host/started_at/job_id) inside the worker lock directory.
- **Stale lock detection**.
  - If lock exists, check age.
  - Best-effort check whether the PID is still running.
- **Recovery policy**.
  - If stale beyond a configurable threshold (e.g. 30–60 minutes), allow takeover.
  - Mark the previous job as `error` with a message like “Stale lock recovered.”
- **Apply the same approach to the DB lock**.

### 3) Crash consistency (write a useful final state)

Why: ensure the UI always transitions to a terminal state even if the worker exits unexpectedly.

- Ensure that:
  - `status.json` is updated to `error` on unexpected termination.
  - `result.json` contains an actionable error message.
- Consider a `register_shutdown_function()` path to catch fatals and record failure.

### 4) Observability improvements

Why: reduce time-to-diagnosis when a large import fails mid-run.

- **Per-job log**.
  - Write `worker.log` under the job directory, timestamped.
  - Include step transitions and counts (validated, upserted, inserted, duplicates).
- **Expose log tail in status endpoint** (bounded).
  - Return a capped `log_tail` (e.g. last 16KB / last 200 lines).
  - Admin UI can show it under a collapsible “Debug” section (especially on errors).
- **Include environment context in logs**.
  - Disk free, PHP memory limit, and other high-signal runtime details.

### 5) Progress/ETA quality

Why: reduce “silent periods” and improve ETA stability across refreshes.

- **Add timestamps to progress** in `status.json`.
  - `progress.started_at` and `progress.updated_at` per step.
- **Update cadence**.
  - Update progress not only every N items, but also at least every X seconds.

### 6) Replay and destructive-operation safety

Why: reduce the chance of accidental destructive actions.

- **Server-side confirmation for destructive replay**.
  - Require an explicit confirmation flag for reload replays (not just client confirm dialogs).
- **Job schema/versioning**.
  - Add a `schema_version` in `meta.json` to make future migrations safer.
- **Replay validation**.
  - Validate saved `manifest.json` exists and matches expected schema.

### 7) Basic rate limiting / abuse protection

Why: avoid accidental repeated clicks or hammering endpoints.

- Simple per-IP throttling for manifest endpoints.
- Ensure all import-related endpoints consistently enforce admin auth.

## Suggested implementation order

If doing only two improvements first:

1. **Stale-lock recovery + lock metadata** (prevents “stuck forever”)
2. **Request size + item count caps** (prevents runaway resource usage)

Everything else can follow once reliability is solid.
