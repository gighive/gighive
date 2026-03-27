# Feature Design: admin_media_import.php

> **Note (2026-03):** `admin_media_import.php` has been renamed to
> `admin_database_load_import_media_from_folder.php`. All references to the old filename
> in this document are historical and refer to the same page under its new name.

## Summary

This document captures the agreed design for a new browser-based media import workflow page:

- `admin_media_import.php`

The goal is to replace the current manual Part II shell/Python workflow with a manifest-aware browser experience while preserving the existing manifest/hash generation model.

The page hosts both steps of the workflow:

- Step 1: Create Manifest and Hashes
- Step 2: Upload Media

The page is intentionally planned as a new dedicated workflow page. Changes to `admin.php` are explicitly deferred until after this page exists and is working.

## Scope for first implementation

Build a new page:

- `admin_media_import.php`

Do not edit `admin.php` yet.

Deferred follow-up task:

- after `admin_media_import.php` is implemented and working, add an entry point from `admin.php`

## Authentication and authorization

`admin_media_import.php` and all supporting backend endpoints are restricted to `admin` users only.

This is consistent with the existing manifest endpoints (`import_manifest_status.php`, `import_manifest_jobs.php`, `import_manifest_cancel.php`) which use the same pattern:

```php
if ($user !== 'admin') { http_response_code(403); exit; }
```

## Page structure

The new page has two separate workflow sections:

- `Reload Database from Folder (destructive)`
- `Add to Database from Folder (non-destructive)`

Each section has two buttons:

- `Create Manifest and Hashes`
- `Upload Media`

The numbered section labels (Section 4, Section 5) from `admin.php` are intentionally dropped on this standalone page. Descriptive names are used instead.

## High-level workflow

The new page preserves a two-part workflow:

1. Create manifest and hashes
2. Upload media using the manifest context created in step 1

The key design decision is that the second step must be manifest-aware and tied to the backend job created by step 1.

## Canonical job identity

The canonical identifier for the workflow is:

- `job_id`

For step 2, `job_id` alone is the canonical input.

The backend already persists manifest jobs under:

- `/var/www/private/import_jobs/<job_id>/`

Current persisted manifest job files/patterns already include:

- `meta.json`
- `manifest.json`
- `status.json`
- `result.json`
- `cancel.json`

The manifest job and the upload job share the same `job_id` and the same job directory. Upload-specific artifacts are added alongside the existing manifest artifacts. The `job_id` is the implicit join key between manifest and upload state — no separate linkage field is needed.

This existing persisted job-status pattern should be followed for the new upload workflow wherever possible.

## Source of truth

The current source of truth for step 2 is:

- `manifest.json`

Unless a future reason appears to change this, the new page should continue to follow that pattern.

## Step 1: Create Manifest and Hashes

Step 1 remains the first action in each section.

Its responsibilities are:

- scan the selected folder
- find supported media files
- compute SHA-256 checksums
- generate/import manifest metadata
- persist manifest job state

### JavaScript state machine migration

The existing Step 1 logic in `admin.php` is driven by two JavaScript state machines:

- `_scanFolderImportRunState` — for the destructive reload section
- `_scanFolderAddRunState` — for the non-destructive add section

Each state machine manages `idle`, `hashing`, `uploading`, `stopping` states and handles UI transitions, progress polling, cancellation, and error handling.

Migrating these state machines to `admin_media_import.php` is a first-class part of Phase 1 implementation work, not an afterthought.

Step 2 will introduce additional upload state machine logic alongside the migrated Step 1 state machines.

### Duplicate hash handling

`checksum_sha256` remains the authoritative key.

However, `source_relpath` must still be shown to the user for reassurance and human verification.

If multiple files in the selected folder have the same checksum, step 1 must not silently continue.

Instead, before the duplicate resolution dialog is shown:

- the pending hashing job is saved to the server under the same job directory
- a new file `duplicates.json` is written containing all duplicate checksum groups and their associated `source_relpath` values
- the job state is set to `awaiting_duplicate_resolution`

Only then is the user shown the duplicate resolution dialog asking:

- `Which one should we keep?`

Meaning:

- which duplicate file path should be retained as the canonical manifest entry for that checksum

Only the chosen file should survive into the finalized manifest for that checksum.

Step 1 is not complete and the manifest is not submitted to the server until all duplicate checksum groups have been resolved by the user.

#### Duplicate resolution recovery

If the user closes the browser before resolving duplicates, the pending job with `awaiting_duplicate_resolution` state is already persisted on the server.

When the user returns to the page:

- the pending job is preselected via the recovery controls
- the page shows the duplicate resolution dialog directly from the saved `duplicates.json`
- the user does not need to re-select their local folder
- the user simply resumes from where they left off

## Step 2: Upload Media

Step 2 is the new browser-driven portion of the workflow.

It must be driven by the manifest job created in step 1.

### Canonical linkage

Step 2 uses:

- `job_id`

The page/backend then reads:

- `manifest.json`

for expected files and workflow context.

### Matching strategy

The authoritative match key is:

- `checksum_sha256`

`source_relpath` is still displayed for user reassurance and context, but checksum is the authoritative matching key.

This keeps matching simple, robust to file renames, and aligned with current checksum-based storage/deduplication behavior.

### Upload transport

The agreed upload transport for step 2 is:

- `TUS`

This choice was made because:

- uploads may run for a long time
- jobs may continue overnight
- browser crashes/reloads/reconnects must be recoverable

### Completion rule

A file is only considered complete after all required post-processing is satisfied:

- media upload is satisfied
- thumbnail handling is satisfied
- database update is satisfied

### Status model

The agreed user-facing states include:

- `pending`
- `matched_locally`
- `uploading`
- `uploaded`
- `already_present`
- `thumbnail_done`
- `db_done`
- `failed`

Internally, `already_present` should be differentiated by artifact type when needed.

Recommended internal breakdown:

- media state
- thumbnail state
- db state

This is important because these are distinct layers of completion.

A file can be:

- already present as media
- missing thumbnail
- not yet finalized in DB

So artifact-specific state should be preserved internally even if a simpler summary is shown in the UI.

### already_present display

User-facing display rules for `already_present`:

- **Fully complete** (media + thumbnail + DB all satisfied): show `Already present`
- **Incomplete** (media present but one or more artifacts missing): show inline what is missing, e.g. `Already present — missing: thumbnail, db` or `Already present — missing: db`
- **Detail view**: an inline toggle that expands to show per-artifact states (media / thumbnail / DB). This is not a separate page — it is hidden text toggled in place on the same row.

### Post-upload state: thumbnail_done and db_done

After a TUS upload completes, the server performs post-processing:

1. generate the thumbnail
2. update the database record

These are server-side operations. The browser cannot know when they complete without polling.

The browser learns `thumbnail_done` and `db_done` states by polling the upload job status endpoint, following the same polling pattern as the existing Step 1 manifest job status polling.

The upload job status endpoint must expose per-file states so the browser can update the UI item-by-item as each file's post-processing completes.

### Resume behavior

When a user resumes an upload job after a crash or browser close, the upload batch must not restart from the beginning.

On resume:

- each manifest entry is checked against the persisted `upload_status.json`
- any file already in a terminal success state (`uploaded`, `thumbnail_done`, `db_done`, `already_present`) is skipped
- only files in `pending`, `failed`, or `uploading` states are processed

This applies both to automatic TUS reconnect and to user-initiated resume after a crash.

This is the same idempotent resume pattern already used in the Step 1 manifest job.

## Upload failure behavior

### TUS-level failures vs. batch-level failures

There are two distinct failure levels in the upload flow:

**Level 1 — TUS-level (transparent, automatic)**

- connection dropped mid-upload
- browser paused or suspended temporarily
- short network interruption

TUS handles these transparently via its resumable protocol. The user should never see a decision dialog for Level 1 failures.

**Level 2 — Batch-level (permanent, requires user decision)**

- file checksum does not match the manifest after upload completes
- server rejects the file (wrong type, oversized, etc.)
- thumbnail generation fails on the server
- DB write fails on the server
- TUS upload finalized but post-processing failed

Only Level 2 failures trigger the user decision dialog.

### Batch failure decision dialog

On the first Level 2 (permanent) failure, the system should pause and offer these choices:

- `Retry this file`
- `Skip this file and continue one-by-one`
- `Skip this file and continue automatically with the default action for remaining failures`
- `Stop batch`

The agreed default action for the rest is:

- `mark failed and continue`

Then summarize failures at the end.

This makes long-running jobs practical while still allowing user control once the first permanent problem occurs.

## Recovery and resiliency

A major design goal is resiliency.

Manifest and upload jobs may continue:

- in the background
- overnight
- after browser refresh or crash

The browser should be able to reconnect later and recover the state from the backend.

### Return/reconnect UX

When a user returns to the page after a crash, reload, or long-running background work, the page automatically detects the current job state by polling the backend on load and renders the appropriate view.

There are three possible states:

**Still running**

- show live polling status as if the user never left
- display progress bar, current file, elapsed time, and counts so far
- no manual action required

**Completed successfully**

- show a summary: e.g. `N files uploaded`
- show an inline toggle or link to detailed upload results
- no continue button needed

**Stopped or failed**

- show a summary of what was done before the stop or failure
- show a clear continue button based on the stage where work stopped:
  - `Continue with manifest/hashing`
  - or `Continue with upload`

 The reconnect experience is summary-first, with detail available on demand via an inline toggle.

 ## Failure Diagnostics and Retry Mechanism

 Step 2 should surface as much actionable failure context as possible in the existing upload logging viewport so operators can understand whether a failure is transient, recoverable, or terminal without leaving the page.

 ### Goals

 - capture enough diagnostics at the time of failure to explain what happened
 - classify failures into retryable vs non-retryable categories
 - preserve this information under the existing `job_id`
 - present a clear `Retry Upload` path that the operator can use after resolving the blocking issue
 - avoid forcing the operator to inspect Docker logs unless deeper investigation is still needed

 ### Diagnostics to surface in the Step 2 logging viewport

 On failure, the browser log viewport should merge client-side and server-side diagnostics into a concise but information-rich sequence.

 Preferred diagnostic fields to surface include:

 - `job_id`
 - `checksum_sha256`
 - `source_relpath`
 - `upload_id` when known
 - TUS endpoint and upload URL/ID (combined; method alone is always PATCH and need not be logged separately)
 - HTTP status code
 - normalized failure code
 - human-readable failure message
 - retryable flag
 - retry attempt count
 - timestamp of first and latest failure for that file

 Where available, the viewport should also show structured environment diagnostics gathered by the backend at or near failure time, for example:

 - upload storage filesystem path and mount point
 - available bytes / percent used for the upload storage path
 - PHP temp/upload directory free space when relevant
 - whether the target media file already exists on disk
 - whether the thumbnail target already exists for videos
 - whether the DB row exists and whether it appears partially finalized
 - whether the TUS hook payload file exists for the upload ID

 These diagnostics should be emitted as structured trace entries rather than only freeform strings so the viewport can render a concise message while still allowing detail inspection.

 ### Failure classification model

 Each per-file Step 2 failure should be classified into a normalized category.

 Recommended fields on each file entry in `upload_status.json`:

 - `retryable` — boolean
 - `failure_code` — normalized machine-readable code
 - `last_error` — most recent human-readable error
 - `last_failed_at` — ISO timestamp
 - `retry_count` — integer
 - `diagnostics` — structured object with the most relevant failure context

 Recommended initial `failure_code` set:

 - `disk_full`
 - `tus_5xx`
 - `tus_4xx`
 - `network_error`
 - `timeout`
 - `local_file_missing`
 - `checksum_mismatch`
 - `finalize_error`
 - `thumbnail_error`
 - `db_error`
 - `invalid_manifest_state`

 Initial retryability guidance:

 - retryable: `disk_full`, `tus_5xx`, `network_error`, `timeout`, `thumbnail_error`
 - not retryable: `local_file_missing`, `invalid_manifest_state`, `checksum_mismatch`
 - `tus_4xx`: retryable except HTTP 409, which indicates a conflicting upload state and is a logic error, not a transient fault
 - context-dependent:
   - `finalize_error`: retryable if caused by a transient condition (DB connection failure, lock timeout); not retryable if a validation error
   - `db_error`: retryable if a transient connection failure; not retryable if a constraint violation

 The system should classify using the most specific known cause. For example, `no space left on device` should map to `disk_full` instead of a generic `tus_5xx`.

 A maximum of 3 retries per file is recommended. Once a file exceeds this count its `retryable` flag is set to `false` and it is treated as terminal regardless of `failure_code`. This prevents infinite retry loops on persistent infrastructure failures.

 ### Server-side diagnostic capture

 The backend should gather failure diagnostics from the application-controlled context immediately after a Step 2 failure is detected.

 Preferred sources:

 - existing per-file failure history already recorded in `upload_status.json` (read before writing the updated failure state, to preserve accumulated context across retries)
 - manifest entry context from `manifest.json`
 - finalize-state checks against the filesystem and DB
 - filesystem free-space checks for the configured TUS data path and target media paths
 - existence checks for hook outputs associated with the TUS upload ID

 There are two distinct server-visible failure paths with different diagnostic capture opportunities:

 **TUS upload failure (PATCH returns 5xx/4xx):** The failure is detected in the browser via the tus-js-client error callback. The server cannot directly observe this event. Server-side environment checks (filesystem free space, hook file existence) should be performed when the backend receives the next status poll after a client-reported failure.

 **Finalize failure (`import_manifest_upload_finalize.php` returns an error):** The server detects this directly. Environment checks and diagnostic context should be captured at the time the finalize request fails and written into the trace and `upload_status.json` immediately.

 This design does not require the web application to shell out to Docker or inspect container logs directly during request handling. The page should surface diagnostics that the application can safely and consistently obtain from its own runtime environment and mounted paths.

 If later needed, deeper container/runtime diagnostics can remain an operator-level task outside the page.

 ### Browser/client diagnostic capture

 The browser should continue logging:

 - TUS start
 - progress milestones
 - retry attempts
 - TUS error objects
 - finalize request/response status
 - polling status changes

 On TUS or finalize failure, the browser should append a client trace entry that includes:

 - request phase
 - HTTP status code if available
 - TUS upload URL or upload ID if available
 - file checksum / `source_relpath`
 - retryability as a best-effort client assessment based on HTTP status code and TUS error detail
 - whether automatic TUS retries were exhausted

 The client's retryability assessment is a first pass only. The server's classification, returned via the next status poll, is authoritative and overrides the client's judgment. The viewport should reflect the server classification once available.

 ### Persisted state behavior on failure and resume

 `upload_status.json` should become the canonical persisted state for retry/recovery decisions.

 Resume rules should be:

 - terminal success states are skipped
 - `pending` files are eligible to upload
 - `failed` files with `retryable=true` are eligible to retry
 - `failed` files with `retryable=false` are shown as blocked until operator action changes the underlying condition or job inputs
 - any `uploading` state found in an existing `upload_status.json` during resume is unconditionally normalized back to `pending`; no active upload tracking is maintained across server restarts or browser reloads, so this is the safest and simplest rule

 The final batch should not be labeled fully complete if retryable work remains.

 Recommended job-level derived states:

 - `in_progress`
 - `blocked_retryable`
 - `complete_success`
 - `complete_failed_terminal`

 The transition from `blocked_retryable` back to `in_progress` is always operator-initiated. The system cannot detect when the blocking condition has been resolved (e.g. disk space freed, network restored), so no automatic transition is attempted. The operator presses `Retry Upload` after resolving the issue, which triggers the transition.

 ### Retry button behavior

 The page should present a dedicated `Retry Upload` button when all of the following are true:

 - a valid `job_id` exists
 - Step 2 is not in `complete_success`
 - one or more files are `pending` or `failed` with `retryable=true`
 - the operator has selected the local source folder **if any retryable file still requires the upload phase** (files blocked only at the finalize stage do not require the local file again)

 If the job is retryable but the local folder is not selected, the page should show a disabled retry button with helper text instructing the operator to reselect the source folder.

 The button label should reflect state:

 - `Upload Media` — no prior Step 2 attempt yet
 - `Retry Upload` — takes precedence when at least one file is `failed` with `retryable=true`; this label wins over `Resume Upload` so the operator sees failures before proceeding
 - `Resume Upload` — interrupted batch with remaining `pending` work and no retryable failures
 - `Uploads Complete` — all files are in a terminal state

 **`Uploads Complete` acknowledgment rule:** The batch transitions to `complete_failed_terminal` and the button label changes to `Uploads Complete` when all `failed retryable` files have either exhausted the maximum retry count or the operator explicitly clicks `Mark as Done` on the failure summary. `Mark as Done` appears only when no `in_progress` or `pending` work remains. Until either condition is met the button stays `Retry Upload`.

 ### Logging viewport behavior on failure

 A "meaningful failure" is a failure after `tus-js-client` has exhausted all automatic internal retries (per the configured `retryDelays` array). Intermediate TUS-internal retry attempts should not produce viewport entries; only the final `onError` callback result is treated as a meaningful failure for display and classification purposes.

 On the first meaningful failure for a file, the viewport should show a compact human-readable summary such as:

 - `PATCH /files/ failed with HTTP 500 — disk_full — retry available after storage is freed`

 The structured detail payload below that summary should include the captured diagnostics object for the file.

 Repeated polling updates should continue to be deduplicated so the viewport remains readable. Diagnostic entries should be appended when there is new information, not on every poll.

 ### Recommended implementation sequence

 1. extend the per-file Step 2 state model with retry and diagnostics fields
 2. classify failures in client and server flows using normalized `failure_code` values
 3. capture server-side environment checks on failure and expose them through `upload_status.json` and trace entries
 4. normalize stale `uploading` entries on resume
 5. update the Step 2 UI to derive batch state and show `Resume Upload` / `Retry Upload` appropriately
 6. refine summary counts so retryable failures are not misrepresented as complete or silently left pending

 ## Persisted upload job state

 The current manifest import workflow already persists status files under the job directory and exposes them through status endpoints.

The new upload workflow follows the same persisted file-based pattern under the same manifest job directory.

Upload-specific persisted artifacts include:

- `duplicates.json` — duplicate checksum groups pending resolution (written before showing the resolution dialog)
- `upload_status.json` — per-file upload state, polled by the browser
- `upload_result.json` — final upload job result
- `upload_cancel.json` — cancellation signal
- `upload_meta.json` — upload job metadata

All of these live under `/var/www/private/import_jobs/<job_id>/` alongside the existing manifest artifacts.

The pattern mirrors the manifest job model:

- persisted JSON state
- pollable by browser
- recoverable after refresh/crash

## Upload Media button states

The `Upload Media` button has four distinct states:

| Job upload state | Button behavior |
|---|---|
| No successful manifest yet | Disabled — `Upload Media` |
| Manifest exists, no upload job yet | Enabled — `Upload Media` |
| Upload job in progress | Disabled — `Upload in Progress…` with link to live progress |
| Upload job complete | Disabled — `Uploads Complete` with option to re-run via recovery controls |

## Job preselection

There are two distinct preselection rules, applied to two different UI controls:

### Upload Media button preselection

Preselect the most recent successful manifest job for that section that does not yet have a completed upload job.

This ensures the button is pointed at the most relevant unfinished work.

### Recovery controls preselection

Preselect the most recent incomplete or failed job for that section, regardless of whether it is a manifest job or an upload job.

This ensures recovery controls are pointed at the most relevant interrupted work.

If a `job_id` is explicitly supplied in the URL, that job overrides both preselection rules and becomes the focus for that section.

## Section behavior on the new page

### User reassurance before action

The page should display enough identifying information for the selected job that the user can determine it is the correct job before pressing the action button.

Examples of useful identifying details include:

- `job_id`
- mode
- created time
- item count
- state
- summary/result message

### Recovery controls

Each section should keep:

- last job summary
- previous jobs dropdown
- retry/recovery controls

If the user attempts retry/recovery on a job that was already successful, the UI should ask for confirmation with messaging along the lines of:

- `Our records indicate your previous job was successful. Do you want to do this?`

### Reopening older jobs

Older manifest jobs must be reopenable later.

Users should be able to:

- revisit old jobs
- continue uploads later
- resume from persisted backend state

## What is deferred

The following is intentionally deferred from the first implementation scope:

- editing `admin.php`
- wiring `admin.php` to point to `admin_media_import.php`

That is a return task after the new page is built and validated.

## File Change Map

### Changed (existing files)

| File | What changes |
|---|---|
| `import_manifest_jobs.php` | Read `status.json` when `result.json` is absent (exposes `awaiting_duplicate_resolution` and other non-terminal states instead of `unknown`); add `recent_jobs` to response for Upload Media button preselection |
| `src/Services/UploadService.php` | Add new method `finalizeManifestTusUpload()` — manifest-aware TUS finalize that updates the existing manifest-created DB row by checksum instead of rejecting it as a duplicate. Existing `finalizeTusUpload()` is untouched. |

### New files

| File | Purpose |
|---|---|
| `admin_media_import.php` | Main page: PHP auth/template shell, two sections (Reload/Add), Step 1 JS state machines migrated from `admin.php`, Step 2 batch upload UI |
| `import_manifest_prepare.php` | POST: receives hashed manifest items from browser, creates `job_id`, writes `meta.json` / `manifest.json` / `duplicates.json` / `status.json`, returns `job_id`. Always used for the submit path — ensures a consistent `job_id` from Step 1 through Step 2. |
| `import_manifest_finalize.php` | POST: receives `job_id` + duplicate resolutions, filters `manifest.json` to remove unchosen duplicates, acquires worker lock, starts `import_manifest_worker.php` on the existing draft job directory |
| `import_manifest_duplicates.php` | GET: returns manifest items and duplicate groups from `duplicates.json` for a given `job_id`. Used for recovery: allows the browser to rebuild the duplicate resolution dialog after a page close without re-scanning the local folder. |
| `import_manifest_upload_start.php` | POST: reads `manifest.json` for a `job_id`, checks which files are already present on disk and in the DB, writes `upload_status.json` with per-file states, returns per-file status to the browser |
| `import_manifest_upload_status.php` | GET: returns `upload_status.json` (or `upload_result.json` if complete) for a `job_id`. Polled by the browser for per-file `thumbnail_done` and `db_done` state updates. |
| `import_manifest_upload_finalize.php` | POST: called by the browser after each TUS upload completes. Calls `UploadService::finalizeManifestTusUpload()`, updates the per-file record in `upload_status.json`, writes `upload_result.json` when all files reach a terminal state. |

### Not changed

The following existing files are used by the new page but require no modifications:

- `import_manifest_lib.php` — all required helpers already exist
- `import_manifest_reload_async.php` — `admin.php` Section 4 continues to use this unchanged
- `import_manifest_add_async.php` — `admin.php` Section 5 continues to use this unchanged
- `import_manifest_worker.php` — `import_manifest_finalize.php` starts the worker with an existing `job_id`; the worker reads the job directory and runs as normal
- `import_manifest_status.php` — already reads `status.json`; no changes needed
- `import_manifest_cancel.php` — same cancel mechanism works for the new flow
- `import_manifest_replay.php` — unchanged
- `admin.php` — explicitly deferred (Phase 5)

## Proposed implementation phases

All phases are internal development sequencing. They all ship together in one change window. Phases are not independent production releases.

### Phase 1

Create `admin_media_import.php` with:

- two sections with descriptive labels
- the step 1 manifest/hash workflow migrated from `admin.php`
- migration of `_scanFolderImportRunState` and `_scanFolderAddRunState` JavaScript state machines from `admin.php` — this is first-class Phase 1 work
- duplicate detection with hard block on manifest submission if duplicates are present (resolution dialog added in Phase 2)
- job summaries and recovery UI

Note: Phase 1 uses `import_manifest_prepare.php` + `import_manifest_finalize.php` (not the existing async endpoints) for the non-duplicate submit path. This is required to establish a consistent `job_id` from Step 1 that Step 2 (Phase 3) will reuse. The existing `import_manifest_reload_async.php` / `import_manifest_add_async.php` are not called by the new page.

Files introduced in this phase: `admin_media_import.php` (new), `import_manifest_prepare.php` (new), `import_manifest_finalize.php` (new), `import_manifest_jobs.php` (patched).

### Phase 2

Add full duplicate checksum resolution at end of step 1:

- save `duplicates.json` and set `awaiting_duplicate_resolution` state before showing dialog
- group duplicates by checksum
- display associated `source_relpath` values
- require keep-choice before finalizing manifest
- support recovery of unresolved duplicate state after browser close

Files introduced in this phase: `import_manifest_duplicates.php` (new). `import_manifest_prepare.php` extended to write `duplicates.json` and set `awaiting_duplicate_resolution` state for the duplicate path.

### Phase 3

Add step 2 manifest-aware upload flow:

- load manifest by `job_id`
- use TUS for upload transport
- match by checksum
- show `source_relpath` as reassurance
- persist upload progress under the same job directory (`upload_status.json`, etc.)
- implement per-file post-processing polling for `thumbnail_done` and `db_done`
- implement resume behavior: skip terminal success states on reconnect

Files introduced in this phase: `src/Services/UploadService.php` (add `finalizeManifestTusUpload()`), `import_manifest_upload_start.php` (new), `import_manifest_upload_status.php` (new), `import_manifest_upload_finalize.php` (new).

### Phase 4

Add full reconnect/recovery behavior:

- three-state reconnect model (still running / completed / stopped+failed)
- auto-detection of job state on page load via backend polling
- inline detail toggle for per-file results
- paused failure-decision UI for batch Level 2 failures
- `already_present` inline missing-artifact display

Files modified in this phase: additions to `admin_media_import.php` only. No new backend files.

### Phase 5

Deferred return task:

- add entry point from `admin.php`

Files modified in this phase: `admin.php` only.

## Notes for implementation

Key invariants to preserve:

- `manifest.json` remains the source of truth for step 2
- `job_id` is the canonical identifier and the implicit join key between manifest and upload artifacts
- checksum is authoritative for matching
- `source_relpath` remains visible for human reassurance
- duplicate checksum ambiguity must be resolved before step 2
- pending duplicate resolution state must be persisted to the server before the dialog is shown
- upload jobs must remain recoverable after browser loss/reload
- on resume, only pending/failed/uploading files are processed — terminal success states are skipped
- TUS handles transient connection failures transparently; user decision dialogs are only shown for permanent (Level 2) failures
- success means all required post-processing is satisfied (media + thumbnail + DB), not merely bytes uploaded
- all endpoints and the page itself are admin-only
